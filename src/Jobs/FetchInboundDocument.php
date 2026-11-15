<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Jobs;

use Illuminate\Support\Facades\DB;
use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\InboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentReceived;
use IntrAgile\EInvoicing\Laravel\Models\InboundMessage;
use IntrAgile\EInvoicing\Laravel\Support\ConfigFlag;
use IntrAgile\EInvoicing\Laravel\Support\InboundRecorder;
use IntrAgile\EInvoicing\Transport\Exception\OutcomeUnknown;
use IntrAgile\EInvoicing\Transport\Exception\TransportException;
use IntrAgile\EInvoicing\Transport\InboundDocument;
use IntrAgile\EInvoicing\Transport\InboundSource;
use InvalidArgumentException;
use Throwable;

/**
 * Fetches a notified document, stores it, announces it, and then acknowledges it at the
 * transport.
 *
 * The document is stored and moved to fetched with one conditional update in a transaction; only
 * the job that wins that update fires DocumentReceived, and only after the commit, so a
 * duplicate job never announces a document twice. The transport is told only after the commit,
 * so a document is never acknowledged before it is safely stored. A row that is already fetched,
 * because an earlier acknowledgement failed, is acknowledged without being fetched or announced
 * again.
 *
 * A failed fetch or acknowledgement is counted in fetch_attempts and recorded on the row, which
 * stays notified or fetched. Either is queued again after the configured sending backoff when it
 * is safe to repeat, while fewer than sending.max_attempts and inbound.max_fetch_attempts have
 * failed and unless the queue connection runs jobs synchronously and cannot delay them; beyond
 * that the next poll of the inbound source retries it. inbound.max_fetch_attempts is the total
 * budget of a document, fetches and acknowledgements together: a row that has used it up is left
 * alone, by this job and by the poll, until an operator retries it with
 * `einvoicing:poll-inbound --include-capped`. The counter never grows past the cap.
 */
final class FetchInboundDocument extends MessageJob
{
    private const DEFAULT_BACKOFF = [30, 120, 600, 1800];

    private const DEFAULT_MAX_ATTEMPTS = 5;

    /**
     * Two requests, each with its connect and response timeouts, plus a margin.
     */
    protected static function jobTimeout(): int
    {
        return 2 * (self::setting('http.timeout', 60) + self::setting('http.connect_timeout', 10)) + 30;
    }

    public function handle(EInvoicingManager $manager): void
    {
        self::assertSettings();

        // The stored XML is never needed here.
        $message = InboundMessage::queryWithoutXml()->find($this->messageId);
        if ($message === null || $message->state === InboundState::Acknowledged || $message->fetch_attempts >= self::attemptCap()) {
            return;
        }

        $source = $manager->inbound($message->transport);

        if ($message->state === InboundState::Notified) {
            $this->fetch($source, $message);

            return;
        }

        $this->acknowledge($source, $message);
    }

    private function fetch(InboundSource $source, InboundMessage $message): void
    {
        $attempts = $message->fetch_attempts;

        try {
            $document = $source->fetch($message->provider_document_id);
        } catch (Throwable $exception) {
            $this->fetchFailed($message, $attempts, $exception);

            return;
        }

        DB::transaction(function () use ($source, $message, $document): void {
            if (! $message->transition(InboundState::Notified, InboundState::Fetched, $this->storedAttributes($message, $document))) {
                return;
            }

            DB::afterCommit(function () use ($source, $message): void {
                try {
                    event(new DocumentReceived($message));
                } finally {
                    // A failing listener does not keep a stored document unacknowledged.
                    $this->acknowledge($source, $message);
                }
            });
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function storedAttributes(InboundMessage $message, InboundDocument $document): array
    {
        // Values already known from the notification are kept.
        $reference = array_filter(
            InboundRecorder::referenceAttributes($document->reference),
            static fn (string $column): bool => $message->getAttribute($column) === null,
            ARRAY_FILTER_USE_KEY,
        );

        return [
            ...$reference,
            'xml' => $document->xml,
            'envelope_id' => $document->envelope?->instanceIdentifier,
            'fetched_at' => now(),
            'last_error' => null,
        ];
    }

    private function fetchFailed(InboundMessage $message, int $attempts, Throwable $exception): void
    {
        $recorded = $message->transition(InboundState::Notified, InboundState::Notified, [
            'fetch_attempts' => self::counted($attempts),
            'last_error' => self::describe($exception),
        ], ['fetch_attempts' => $attempts]);

        if ($recorded && $exception instanceof TransportException && $exception->isRetryable()) {
            $this->retry($message->id, $attempts);
        }

        // A document that cannot be read is left for an operator; anything else is a defect.
        if (! $exception instanceof TransportException
            && ! $exception instanceof UnsafeXml
            && ! $exception instanceof UnsupportedDocument
            && ! $exception instanceof InvalidArgumentException) {
            throw $exception;
        }
    }

    private function acknowledge(InboundSource $source, InboundMessage $message): void
    {
        if (! ConfigFlag::enabled('inbound.acknowledge')) {
            return;
        }

        try {
            $source->acknowledge($message->provider_document_id);
        } catch (TransportException $exception) {
            $attempts = $message->fetch_attempts;
            $recorded = $message->transition(InboundState::Fetched, InboundState::Fetched, [
                'fetch_attempts' => self::counted($attempts),
                'last_error' => self::describe($exception),
            ], ['fetch_attempts' => $attempts]);

            // Acknowledging is idempotent, so an acknowledgement whose outcome is unknown is
            // repeated as well. A lost update means another job has moved the row on.
            if ($recorded && ($exception->isRetryable() || $exception instanceof OutcomeUnknown)) {
                $this->retry($message->id, $attempts);
            }

            return;
        }

        $message->transition(InboundState::Fetched, InboundState::Acknowledged, [
            'acknowledged_at' => now(),
            'last_error' => null,
        ]);
    }

    /**
     * Queues the job again after the backoff for the failed attempt, while attempts remain and
     * the connection can delay jobs. Once sending.max_attempts are used up, only the next poll of
     * the inbound source queues the document again, one job per poll, until the cap is reached.
     *
     * @param  int  $attempts  the failed attempts recorded before this one
     */
    private function retry(int $messageId, int $attempts): void
    {
        $budget = min(self::setting('sending.max_attempts', self::DEFAULT_MAX_ATTEMPTS), self::attemptCap());
        $connection = $this->queueConnection();
        if ($attempts + 1 >= $budget || self::runsSynchronously($connection)) {
            return;
        }

        self::schedule($messageId, self::delayAt('sending.backoff', self::DEFAULT_BACKOFF, $attempts), $attempts + 1, $connection);
    }

    /**
     * The failed attempts after one more, never more than the cap.
     */
    private static function counted(int $attempts): int
    {
        return min($attempts + 1, self::attemptCap());
    }

    private static function describe(Throwable $exception): string
    {
        return $exception::class.': '.$exception->getMessage();
    }
}
