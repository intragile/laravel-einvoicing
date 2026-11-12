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
 * late report never changes a delivered or failed row and never fires an event twice.
 */
final class DeliveryRecorder
{
    /**
     * @return bool whether the row changed
     */
    public function record(OutboundMessage $message, DeliveryState $state, ?DeliveryFailure $failure = null): bool
    {
        return match ($state) {
            DeliveryState::Delivered => $this->delivered($message),
            DeliveryState::Failed => $this->failed($message, $failure),
            DeliveryState::Pending, DeliveryState::Unknown => false,
        };
    }

    private function delivered(OutboundMessage $message): bool
    {
        if (! $message->transition(OutboundState::Pending, OutboundState::Delivered, ['finished_at' => now()])) {
            return false;
        }

        event(new DocumentDelivered($message));

        return true;
    }

    private function failed(OutboundMessage $message, ?DeliveryFailure $failure): bool
    {
        if (! $message->transition(OutboundState::Pending, OutboundState::Failed, [
            'failure_category' => $failure->category ?? FailureCategory::Other,
            'failure_message' => $failure?->message,
            'provider_code' => $failure?->providerCode,
            'finished_at' => now(),
        ])) {
            return false;
        }

        event(new DocumentFailed($message));

        return true;
    }
}
