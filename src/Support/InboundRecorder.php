<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use IntrAgile\EInvoicing\Laravel\Enums\InboundState;
use IntrAgile\EInvoicing\Laravel\Jobs\FetchInboundDocument;
use IntrAgile\EInvoicing\Laravel\Models\InboundMessage;
use IntrAgile\EInvoicing\Transport\InboundReference;

/**
 * Records that a transport has a document for us, from a webhook or a poll of its pending list.
 *
 * A document is stored once per transport and provider id: a repeated notification, or a
 * webhook and a poll reporting the same document, returns the existing row and queues nothing,
 * so the document is fetched and announced once.
 */
final class InboundRecorder
{
    /**
     * The row of the notified document, created in the notified state when it is new, in which
     * case the job that fetches it is queued after the open transaction commits.
     */
    public function notify(string $transport, string $documentId, ?InboundReference $reference = null): InboundMessage
    {
        $existing = $this->find($transport, $documentId);
        if ($existing !== null) {
            return $existing;
        }

        try {
            // A savepoint inside an open transaction keeps it usable after a duplicate insert.
            $message = DB::transaction(fn (): InboundMessage => InboundMessage::query()->create([
                'transport' => $transport,
                'provider_document_id' => $documentId,
                'state' => InboundState::Notified,
                ...self::referenceAttributes($reference),
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            // Another process stored the same notification in the meantime.
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
        return InboundMessage::queryWithoutXml()
            ->where('transport', $transport)
            ->where('provider_document_id', $documentId)
            ->first();
    }
}
