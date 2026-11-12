<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Console;

use Illuminate\Console\Command;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Support\InboundRecorder;
use IntrAgile\EInvoicing\Transport\Exception\TransportException;

/**
 * Asks a transport for its pending received documents and records the ones not seen before,
 * each of which is then fetched, stored and acknowledged by a queued job.
 *
 * Schedule it to receive documents without webhooks, or as a safety net next to them:
 * `Schedule::command('einvoicing:poll-inbound')->everyFiveMinutes();`
 */
final class PollInboundCommand extends Command
{
    protected $signature = 'einvoicing:poll-inbound
        {--transport= : Transport name, default the default transport}';

    protected $description = 'Record the documents waiting at an inbound transport and queue their intake';

    public function handle(EInvoicingManager $manager, InboundRecorder $recorder): int
    {
        $option = $this->option('transport');

        try {
            $transport = is_string($option) && $option !== '' ? $option : $manager->defaultName();
            $pending = $manager->inbound($transport)->pending();
        } catch (ConfigurationException|TransportException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $new = 0;
        foreach ($pending as $reference) {
            $message = $recorder->notify($transport, $reference->documentId, $reference);
            if ($message->wasRecentlyCreated) {
                $new++;
            }
        }

        $this->components->info(sprintf('%d new, %d already known.', $new, count($pending) - $new));

        return self::SUCCESS;
    }
}
