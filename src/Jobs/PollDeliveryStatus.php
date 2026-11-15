<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Jobs;

use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Support\ConfigFlag;
use IntrAgile\EInvoicing\Laravel\Support\DeliveryRecorder;
use IntrAgile\EInvoicing\Transport\Exception\TransportException;

/**
 * Asks the transport whether a pending document has been delivered, and asks again later while
 * it has not.
 *
 * Every poll, including one that fails with a transport error, counts against the configured
 * number of checks; once they are used up polling stops silently and the row stays pending, so a
 * notification can still finish it. The check counter is claimed with a conditional update, so a
 * duplicate job for the same check does nothing. A job belongs to one send, identified by the
 * row's attempt count when it was scheduled; once the row has been sent again, the job and the
 * polls it would schedule do nothing.
 */
final class PollDeliveryStatus extends MessageJob
{
    private const DEFAULT_DELAYS = [60, 300, 900, 3600, 21600];

    private const DEFAULT_MAX_CHECKS = 10;

    /**
     * The longest configured wait between two status polls.
     */
    public static function longestDelay(): int
    {
        return self::longestDelayOf('status.delays', self::DEFAULT_DELAYS);
    }

    /**
     * Schedules the first poll for a document that has just become pending, unless polling is
     * disabled or the queue connection cannot delay jobs.
     *
     * @param  string|null  $connection  the queue connection; the configured one when null
     */
    public static function start(int $messageId, int $attempts, ?string $connection = null): void
    {
        if (! ConfigFlag::enabled('status.poll') || self::runsSynchronously($connection)) {
            return;
        }

        self::schedule($messageId, self::delayAt('status.delays', self::DEFAULT_DELAYS, 0), $attempts, $connection);
    }

    /**
     * One request with its connect and response timeouts, plus a margin.
     */
    protected static function jobTimeout(): int
    {
        return self::setting('http.timeout', 60) + self::setting('http.connect_timeout', 10) + 30;
    }

    public function handle(EInvoicingManager $manager, DeliveryRecorder $recorder): void
    {
        // Before the check is counted, so an invalid setting never ends the poll chain midway.
        self::assertSettings();

        $message = OutboundMessage::query()->find($this->messageId);
        if ($message === null
            || $message->state !== OutboundState::Pending
            || $message->attempts !== $this->attempt
            || $message->provider_document_id === null) {
            return;
        }
        $documentId = $message->provider_document_id;

        $maxChecks = self::setting('status.max_attempts', self::DEFAULT_MAX_CHECKS);
        $checks = $message->status_checks;
        if ($checks >= $maxChecks) {
            return;
        }

        // A configuration problem propagates before the check is counted.
        $transport = $manager->transport($message->transport);

        if (! $message->transition(OutboundState::Pending, OutboundState::Pending, ['status_checks' => $checks + 1], [
            'status_checks' => $checks,
            'attempts' => $this->attempt,
            'provider_document_id' => $documentId,
        ])) {
            return;
        }
        $checks++;

        try {
            $status = $transport->status($documentId);
        } catch (TransportException) {
            $this->again($message, $checks, $maxChecks);

            return;
        }

        // A status about another document says nothing about this one.
        if ($status->documentId !== $documentId
            || ! $recorder->record($message, $status->state, $status->failure, $documentId)) {
            $this->again($message, $checks, $maxChecks);
        }
    }

    /**
     * Schedules the next poll while the row is still pending and checks remain.
     */
    private function again(OutboundMessage $message, int $checks, int $maxChecks): void
    {
        $connection = $this->queueConnection();
        if ($message->state !== OutboundState::Pending || $checks >= $maxChecks || self::runsSynchronously($connection)) {
            return;
        }

        self::schedule($message->id, self::delayAt('status.delays', self::DEFAULT_DELAYS, $checks), $this->attempt, $connection);
    }
}
