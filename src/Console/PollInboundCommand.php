<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\InboundState;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Jobs\FetchInboundDocument;
use IntrAgile\EInvoicing\Laravel\Ksef\KsefManager;
use IntrAgile\EInvoicing\Laravel\Support\ConfigFlag;
use IntrAgile\EInvoicing\Laravel\Support\InboundRecorder;
use IntrAgile\EInvoicing\Transport\Exception\TransportException;

/**
 * Asks a transport for its pending received documents and records the ones not seen before,
 * each of which is then fetched, stored and acknowledged by a queued job. A known document that
 * is still pending and not acknowledged here, because an earlier intake stopped without a retry,
 * is queued again, until it has used up inbound.max_fetch_attempts. Such a capped document is
 * skipped and counted; --include-capped gives each one exactly one more attempt.
 *
 * Schedule it to receive documents without webhooks, or as a safety net next to them:
 * `Schedule::command('einvoicing:poll-inbound')->everyFiveMinutes();`
 *
 * The KSeF inbox (--transport=ksef) is read at most once per
 * einvoicing.ksef.inbound_min_interval_minutes, as KSeF allows 20 metadata queries per hour: a run
 * sooner than that after the start of the previous one, less a minute of slack for scheduler
 * jitter (at least one minute in all), is skipped and says so. The start of the last run is kept
 * in the default cache store. Schedule it, for example, with
 * `Schedule::command('einvoicing:poll-inbound --transport=ksef')->everyFifteenMinutes()->withoutOverlapping();`
 */
final class PollInboundCommand extends Command
{
    protected $signature = 'einvoicing:poll-inbound
        {--transport= : Transport name, default the default transport}
        {--include-capped : Retry each document that has used up inbound.max_fetch_attempts once}';

    /** Seconds of scheduler jitter tolerated between two KSeF inbox runs. */
    private const KSEF_INTERVAL_SLACK = 60;

    protected $description = 'Record the documents waiting at an inbound transport and queue their intake';

    public function handle(EInvoicingManager $manager, InboundRecorder $recorder): int
    {
        $option = $this->option('transport');

        try {
            $transport = is_string($option) && $option !== '' ? $option : $manager->defaultName();
            $cap = FetchInboundDocument::attemptCap();
            if ($transport === EInvoicingManager::KSEF && ! $this->claimKsefRun($manager->ksef())) {
                return self::SUCCESS;
            }
            $pending = $manager->inbound($transport)->pending();
        } catch (ConfigurationException|TransportException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        // Without acknowledgements a fetched document is finished, though it stays pending.
        $acknowledge = ConfigFlag::enabled('inbound.acknowledge');
        $includeCapped = (bool) $this->option('include-capped');
        $new = 0;
        $retried = 0;
        $capped = 0;
        foreach ($pending as $reference) {
            $message = $recorder->notify($transport, $reference->documentId, $reference);
            if ($message->wasRecentlyCreated) {
                $new++;

                continue;
            }
            if ($message->state !== InboundState::Notified
                && ($message->state !== InboundState::Fetched || ! $acknowledge)) {
                continue;
            }

            // Still pending at the transport and not finished here: an earlier intake stopped
            // without a retry. The job is idempotent, so a duplicate is harmless.
            if ($message->fetch_attempts >= $cap) {
                // One attempt left, so a failure brings the row back to the cap.
                if (! $includeCapped || ! $message->transition($message->state, $message->state, [
                    'fetch_attempts' => $cap - 1,
                ], ['fetch_attempts' => $message->fetch_attempts])) {
                    $capped++;

                    continue;
                }
            }
            FetchInboundDocument::schedule($message->id);
            $retried++;
        }

        $this->components->info(sprintf(
            '%d new, %d retried, %d already known, %d capped.',
            $new,
            $retried,
            count($pending) - $new - $retried - $capped,
            $capped,
        ));
        if ($capped > 0) {
            $this->components->warn(sprintf(
                '%d documents have used up einvoicing.inbound.max_fetch_attempts; see last_error on their rows and retry them with --include-capped.',
                $capped,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Records the start of a KSeF inbox run, unless the previous one started less than
     * einvoicing.ksef.inbound_min_interval_minutes ago; then the skip is reported and false is
     * returned. The record is added atomically, so of two runs started together only one reads
     * the inbox.
     *
     * @throws ConfigurationException when a KSeF setting is invalid
     */
    private function claimKsefRun(KsefManager $ksef): bool
    {
        $settings = $ksef->settings();
        $minutes = $settings->inboundMinIntervalMinutes;
        if ($minutes === 0) {
            return true;
        }
        $nip = config('einvoicing.ksef.nip');
        $key = sprintf('einvoicing:ksef:inbound-run:%s:%s', $settings->environment->value, is_scalar($nip) ? trim((string) $nip) : '');
        $now = now()->getTimestamp();
        // A minute of slack, so a scheduled run that started a few seconds late does not make the
        // next on-time run look early.
        if (Cache::add($key, $now, max(self::KSEF_INTERVAL_SLACK, $minutes * 60 - self::KSEF_INTERVAL_SLACK))) {
            return true;
        }

        $last = Cache::get($key);
        $this->components->warn(sprintf(
            'Skipped: the KSeF inbox was last read %s; runs are at least einvoicing.ksef.inbound_min_interval_minutes (%d) apart, as KSeF allows 20 metadata queries per hour.',
            is_int($last) ? sprintf('%d minutes ago', intdiv(max(0, $now - $last), 60)) : 'recently',
            $minutes,
        ));

        return false;
    }
}
