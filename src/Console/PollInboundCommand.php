<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Console;

use Illuminate\Console\Command;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\InboundState;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Jobs\FetchInboundDocument;
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
 */
final class PollInboundCommand extends Command
{
    protected $signature = 'einvoicing:poll-inbound
        {--transport= : Transport name, default the default transport}
        {--include-capped : Retry each document that has used up inbound.max_fetch_attempts once}';

    protected $description = 'Record the documents waiting at an inbound transport and queue their intake';

    public function handle(EInvoicingManager $manager, InboundRecorder $recorder): int
    {
        $option = $this->option('transport');

        try {
            $transport = is_string($option) && $option !== '' ? $option : $manager->defaultName();
            $cap = FetchInboundDocument::attemptCap();
            $pending = $manager->inbound($transport)->pending();
        } catch (ConfigurationException|TransportException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        // Without acknowledgements a fetched document is finished, though it stays pending.
        $acknowledge = (bool) config('einvoicing.inbound.acknowledge', true);
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
}
