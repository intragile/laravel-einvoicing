<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\InboundState;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Jobs\FetchInboundDocument;
use IntrAgile\EInvoicing\Laravel\Models\InboundMessage;
use IntrAgile\EInvoicing\Transport\InboundReference;
use IntrAgile\EInvoicing\Transport\Ksef\KsefTransport;
use InvalidArgumentException;

/**
 * Records that a transport has a document for us, from a webhook or a poll of its pending list.
 *
 * A document is stored once per transport and provider id: a repeated notification, or a
 * webhook and a poll reporting the same document, returns the existing row and queues nothing,
 * so the document is fetched and announced once.
 *
 * A KSeF invoice may be listed as "<KSeF number>@<date>" or as its plain KSeF number; both forms
 * match the same row, which keeps the id it was first recorded with (the fetch needs the full id
 * to skip a metadata query).
 *
 * The unique key covers the exact id only, so two processes recording the same KSeF invoice at the
 * same moment, one with each form, can both insert, leaving two rows for one invoice, each of
 * which can be fetched and announced with its own DocumentReceived. This needs concurrent intakes (overlapping
 * polls) and a window that lists the invoice without metadata, so it is rare; consumers that must
 * never handle an invoice twice deduplicate by KsefTransport::ksefNumberOf().
 */
final class InboundRecorder
{
    /**
     * The row of the notified document, created in the notified state when it is new, in which
     * case the job that fetches it is queued after the open transaction commits.
     *
     * When another process stores the same document concurrently, the insert loses on the unique
     * key and the winner's row is returned. Called inside an outer transaction under REPEATABLE
     * READ, the MySQL default, that row may not be visible to this transaction yet; the unique
     * violation is then rethrown, and the document is picked up on the next notification or poll.
     *
     * @throws UniqueConstraintViolationException when a concurrent insert won but its row is not visible here
     * @throws ConfigurationException when a numeric job setting is invalid; nothing is stored then
     */
    public function notify(string $transport, string $documentId, ?InboundReference $reference = null): InboundMessage
    {
        $existing = $this->find($transport, $documentId);
        if ($existing !== null) {
            return $existing;
        }

        // Before the row is written, so an invalid setting leaves no notified row without a job.
        FetchInboundDocument::assertSettings();

        try {
            // A savepoint inside an open transaction keeps it usable after a duplicate insert.
            $message = DB::transaction(fn (): InboundMessage => InboundMessage::query()->create([
                'transport' => $transport,
                'provider_document_id' => $documentId,
                'state' => InboundState::Notified,
                ...self::referenceAttributes($reference),
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            // Another process stored the same notification in the meantime. Inside an outer
            // transaction under REPEATABLE READ (the MySQL default) its row may not be visible
            // yet; the violation is then rethrown, and the row is found on the next try: the
            // provider re-sending the webhook after the 500 answer, or the next poll-inbound.
            return $this->find($transport, $documentId) ?? throw $exception;
        }

        FetchInboundDocument::schedule($message->id);

        return $message;
    }

    /**
     * The columns a reference describes; only values the reference carries.
     *
     * @return array<string, mixed>
     */
    public static function referenceAttributes(?InboundReference $reference): array
    {
        if ($reference === null) {
            return [];
        }

        return array_filter([
            'sender' => $reference->sender?->toString(),
            'receiver' => $reference->receiver?->toString(),
            'document_type' => $reference->documentType,
            'received_at' => $reference->receivedAt,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function find(string $transport, string $documentId): ?InboundMessage
    {
        $query = InboundMessage::queryWithoutXml()->where('transport', $transport);
        $ksefNumber = $transport === EInvoicingManager::KSEF ? self::ksefNumber($documentId) : null;
        if ($ksefNumber === null) {
            return $query->where('provider_document_id', $documentId)->first();
        }

        // A KSeF number holds letters, digits and hyphens only, so it carries no LIKE wildcard,
        // and the prefix match uses the (transport, provider_document_id) index.
        return $query
            ->where(static fn ($where) => $where
                ->where('provider_document_id', $ksefNumber)
                ->orWhere('provider_document_id', 'like', $ksefNumber.'@%'))
            ->orderBy('id')
            ->first();
    }

    /**
     * The KSeF number of a KSeF inbound id, or null when the id is not one.
     */
    private static function ksefNumber(string $documentId): ?string
    {
        try {
            return KsefTransport::ksefNumberOf($documentId);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
