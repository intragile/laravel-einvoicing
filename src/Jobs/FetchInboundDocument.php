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
 * A failed fetch is counted and recorded on the row, which stays notified; a failed
 * acknowledgement is recorded and the row stays fetched. Either is queued again after the
 * configured sending backoff when it is safe to repeat, unless the queue connection runs jobs
 * synchronously and cannot delay them.
 */
final class FetchInboundDocument extends MessageJob
{
    private const DEFAULT_BACKOFF = [30, 120, 600, 1800];

    /**
     * Two requests, each with its connect and response timeouts, plus a margin.
     */
    protected static function jobTimeout(): int
    {
        return 2 * (self::setting('http.timeout', 60) + self::setting('http.connect_timeout', 10)) + 30;
    }

    public function handle(EInvoicingManager $manager): void
    {
        $message = InboundMessage::query()->find($this->messageId);
        if ($message === null || $message->state === InboundState::Acknowledged) {
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
            'fetch_attempts' => $attempts + 1,
            'last_error' => self::describe($exception),
        ], ['fetch_attempts' => $attempts]);

        if ($recorded && $exception instanceof TransportException && $exception->isRetryable() && ! self::runsSynchronously()) {
            self::schedule($message->id, self::delayAt('sending.backoff', self::DEFAULT_BACKOFF, $attempts));
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
        if (! config('einvoicing.inbound.acknowledge', true)) {
            return;
        }

        try {
            $source->acknowledge($message->provider_document_id);
        } catch (TransportException $exception) {
            $message->transition(InboundState::Fetched, InboundState::Fetched, ['last_error' => self::describe($exception)]);

            // Acknowledging is idempotent, so an acknowledgement whose outcome is unknown is
            // repeated as well.
            if (($exception->isRetryable() || $exception instanceof OutcomeUnknown) && ! self::runsSynchronously()) {
                self::schedule($message->id, self::delayAt('sending.backoff', self::DEFAULT_BACKOFF, $this->attempt), $this->attempt + 1);
            }

            return;
        }

        $message->transition(InboundState::Fetched, InboundState::Acknowledged, [
            'acknowledged_at' => now(),
            'last_error' => null,
        ]);
    }

    private static function describe(Throwable $exception): string
    {
        return $exception::class.': '.$exception->getMessage();
    }
}
