<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Jobs;

use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentDelivered;
use IntrAgile\EInvoicing\Laravel\Events\DocumentFailed;
use IntrAgile\EInvoicing\Laravel\Events\DocumentSent;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Transport\DeliveryState;
use IntrAgile\EInvoicing\Transport\FailureCategory;
use IntrAgile\EInvoicing\Transport\SendResult;
use InvalidArgumentException;
use RuntimeException;

/**
 * Hands a queued document to its transport at most once per claim.
 *
 * The row is claimed atomically (queued to sending, conditional on its attempt count) before the
 * transport is called, so a duplicate job or a second worker finds it claimed and does nothing.
 */
final class SendDocument extends MessageJob
{
    /**
     * One request with its connect and response timeouts, plus local work such as validation.
     */
    protected static function jobTimeout(): int
    {
        return self::setting('http.timeout', 60) + self::setting('http.connect_timeout', 10) + 30;
    }

    public function handle(EInvoicingManager $manager): void
    {
        $message = OutboundMessage::query()->find($this->messageId);
        if ($message === null || $message->state !== OutboundState::Queued) {
            return;
        }

        // A configuration or rule set problem propagates and leaves the row queued.
        $transport = $manager->transport($message->transport);

        try {
            $document = $message->toOutboundDocument();
        } catch (InvalidArgumentException $e) {
            $this->failUnsent($message, $e);

            return;
        }

        $claim = $message->attempts + 1;
        if (! $message->transition(OutboundState::Queued, OutboundState::Sending, ['attempts' => $claim], ['attempts' => $claim - 1])) {
            return;
        }

        $this->accepted($message, $transport->send($document), $claim);
    }

    /**
     * Records the transport's answer and fires the events for the state it reached.
     */
    private function accepted(OutboundMessage $message, SendResult $result, int $claim): void
    {
        $state = match ($result->state) {
            DeliveryState::Delivered => OutboundState::Delivered,
            DeliveryState::Failed => OutboundState::Failed,
            DeliveryState::Pending, DeliveryState::Unknown => OutboundState::Pending,
        };
        $failure = $result->failure;
        $now = now();

        $recorded = $message->transition(OutboundState::Sending, $state, [
            'provider_document_id' => $result->documentId,
            'peppol_message_id' => $result->peppolMessageId,
            'envelope_id' => $result->envelopeId,
            'failure_category' => $state === OutboundState::Failed ? ($failure->category ?? FailureCategory::Other) : null,
            'failure_message' => $state === OutboundState::Failed ? $failure?->message : null,
            'provider_code' => $state === OutboundState::Failed ? $failure?->providerCode : null,
            'sent_at' => $now,
            'finished_at' => $state->isTerminal() ? $now : null,
        ], ['attempts' => $claim]);

        if (! $recorded) {
            // The claim was taken over while this worker was sending: surface the accepted document.
            report(new RuntimeException(sprintf(
                'Outbound document %d was accepted by its transport as %s after its sending claim ended; reconcile it manually.',
                $message->id,
                $result->documentId,
            )));

            return;
        }

        event(new DocumentSent($message));
        if ($state === OutboundState::Delivered) {
            event(new DocumentDelivered($message));
        } elseif ($state === OutboundState::Failed) {
            event(new DocumentFailed($message));
        }
    }

    /**
     * Fails a document that cannot be rebuilt from its row; nothing was sent.
     */
    private function failUnsent(OutboundMessage $message, InvalidArgumentException $e): void
    {
        if ($message->transition(OutboundState::Queued, OutboundState::Failed, [
            'failure_category' => FailureCategory::Other,
            'failure_message' => $e->getMessage(),
            'finished_at' => now(),
        ])) {
            event(new DocumentFailed($message));
        }
    }
}
