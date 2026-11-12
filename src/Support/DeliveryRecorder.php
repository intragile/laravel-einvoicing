<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Support;

use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentDelivered;
use IntrAgile\EInvoicing\Laravel\Events\DocumentFailed;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Transport\DeliveryFailure;
use IntrAgile\EInvoicing\Transport\DeliveryState;
use IntrAgile\EInvoicing\Transport\FailureCategory;

/**
 * Records a delivery state reported for a sent document, from a status poll or a notification.
 *
 * Only a pending row moves, and only to a final state, with a conditional update: a repeated or
 * late report never changes a delivered or failed row and never fires an event twice. The update
 * also requires the row to still carry the provider document id the state was reported for, so a
 * report about an earlier send never settles a document that has been sent again since.
 */
final class DeliveryRecorder
{
    /**
     * @param  string|null  $documentId  The provider document id the state was reported for; by
     *                                   default the id on the given model as it was read.
     * @return bool whether the row changed
     */
    public function record(OutboundMessage $message, DeliveryState $state, ?DeliveryFailure $failure = null, ?string $documentId = null): bool
    {
        $documentId ??= $message->provider_document_id;
        if ($documentId === null) {
            return false;
        }

        return match ($state) {
            DeliveryState::Delivered => $this->delivered($message, $documentId),
            DeliveryState::Failed => $this->failed($message, $documentId, $failure),
            DeliveryState::Pending, DeliveryState::Unknown => false,
        };
    }

    private function delivered(OutboundMessage $message, string $documentId): bool
    {
        if (! $message->transition(OutboundState::Pending, OutboundState::Delivered, ['finished_at' => now()], ['provider_document_id' => $documentId])) {
            return false;
        }

        event(new DocumentDelivered($message));

        return true;
    }

    private function failed(OutboundMessage $message, string $documentId, ?DeliveryFailure $failure): bool
    {
        if (! $message->transition(OutboundState::Pending, OutboundState::Failed, [
            'failure_category' => $failure->category ?? FailureCategory::Other,
            'failure_message' => $failure?->message,
            'provider_code' => $failure?->providerCode,
            'finished_at' => now(),
        ], ['provider_document_id' => $documentId])) {
            return false;
        }

        event(new DocumentFailed($message));

        return true;
    }
}
