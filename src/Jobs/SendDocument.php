<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Jobs;

use Illuminate\Database\UniqueConstraintViolationException;
use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentDelivered;
use IntrAgile\EInvoicing\Laravel\Events\DocumentFailed;
use IntrAgile\EInvoicing\Laravel\Events\DocumentSent;
use IntrAgile\EInvoicing\Laravel\Events\DocumentUnconfirmed;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Transport\DeliveryState;
use IntrAgile\EInvoicing\Transport\Exception\AuthenticationFailed;
use IntrAgile\EInvoicing\Transport\Exception\DocumentRejected;
use IntrAgile\EInvoicing\Transport\Exception\InvalidDocument;
use IntrAgile\EInvoicing\Transport\Exception\RateLimited;
use IntrAgile\EInvoicing\Transport\Exception\RecipientUnreachable;
use IntrAgile\EInvoicing\Transport\Exception\TransportException;
use IntrAgile\EInvoicing\Transport\FailureCategory;
use IntrAgile\EInvoicing\Transport\SendResult;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Hands a queued document to its transport at most once per claim.
 *
 * The row is claimed atomically (queued to sending, conditional on its attempt count) before the
 * transport is called, so a duplicate job or a second worker finds it claimed and does nothing.
 * A failure is retried only when the transport reports that the document was definitely not
 * accepted and that trying again may help; a send whose outcome is not known becomes unconfirmed
 * and is never sent again automatically.
 */
final class SendDocument extends MessageJob
{
    private const DEFAULT_BACKOFF = [30, 120, 600, 1800];

    /**
     * One request with its connect and response timeouts, plus local work such as validation.
     */
    protected static function jobTimeout(): int
    {
        return self::setting('http.timeout', 60) + self::setting('http.connect_timeout', 10) + 30;
    }

    /**
     * Seconds after which a sending claim is over: the job's timeout plus a margin. A row still
     * sending after that has lost its worker.
     */
    public static function claimLifetime(): int
    {
        return self::jobTimeout() + 30;
    }

    /**
     * The longest configured wait before a retried send.
     */
    public static function longestBackoff(): int
    {
        return self::longestDelayOf('sending.backoff', self::DEFAULT_BACKOFF);
    }

    public function handle(EInvoicingManager $manager): void
    {
        // Before the claim: a setting read after the transport answered must not fail there.
        self::assertSettings();

        $message = OutboundMessage::query()->find($this->messageId);
        if ($message === null || $message->state !== OutboundState::Queued) {
            return;
        }

        // A configuration or rule set problem propagates and leaves the row queued. The document
        // is validated here, before the claim, so the bare driver sends it.
        $transport = $manager->transport($message->transport, validated: false);
        $validator = $manager->validator();

        try {
            $document = $message->toOutboundDocument();
        } catch (InvalidArgumentException $e) {
            $this->failUnsent($message, FailureCategory::Other, $e->getMessage());

            return;
        }

        if ($validator !== null) {
            try {
                $report = $validator->validate($document->xml);
            } catch (UnsupportedDocument|UnsafeXml $e) {
                $this->failUnsent($message, FailureCategory::Validation, $e->getMessage());

                return;
            }
            if (! $report->isValid()) {
                $this->failUnsent($message, FailureCategory::Validation, (new InvalidDocument($report))->getMessage());

                return;
            }
        }

        // The expiry is scheduled before the claim, so a worker killed right after claiming
        // still leaves a job that ends the claim. It acts only on a row still sending under
        // this claim, so the job left behind when the claim is lost below does nothing; and
        // when it cannot be scheduled, the error propagates with the row still unclaimed.
        $claim = $message->attempts + 1;
        $connection = $this->queueConnection();
        $synchronous = self::runsSynchronously($connection);
        if (! $synchronous) {
            ExpireSendingClaim::schedule($message->id, self::claimLifetime(), $claim, $connection);
        }

        if (! $message->transition(OutboundState::Queued, OutboundState::Sending, ['attempts' => $claim], ['attempts' => $claim - 1])) {
            return;
        }

        try {
            $result = $transport->send($document);
        } catch (Throwable $e) {
            $this->sendFailed($message, $e, $claim, $synchronous);

            return;
        }

        // Outside the try block: a failure to record an accepted send must never look like a
        // failed send, which could be retried.
        $this->accepted($message, $result, $claim);
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

        try {
            $recorded = $this->settle($message, $claim, $state, [
                'provider_document_id' => $result->documentId,
                'peppol_message_id' => $result->peppolMessageId,
                'envelope_id' => $result->envelopeId,
                'failure_category' => $state === OutboundState::Failed ? ($failure->category ?? FailureCategory::Other) : null,
                'failure_message' => $state === OutboundState::Failed ? $failure?->message : null,
                'provider_code' => $state === OutboundState::Failed ? $failure?->providerCode : null,
                'sent_at' => $now,
                'finished_at' => $state->isTerminal() ? $now : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->duplicateDocumentId($message, $claim, $result->documentId);

            return;
        }

        if (! $recorded) {
            // The row was claimed again while this worker was sending: surface the accepted document.
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
        } else {
            PollDeliveryStatus::start($message->id, $claim, $this->queueConnection());
        }
    }

    /**
     * Settles an accepted send whose provider document id is already recorded on another row of
     * the same transport. The document may have been delivered, so the row becomes unconfirmed
     * for an operator to reconcile, and the accepted id is reported rather than lost.
     */
    private function duplicateDocumentId(OutboundMessage $message, int $claim, string $documentId): void
    {
        $reason = sprintf(
            'The transport accepted the document as %s, but that provider document id is already recorded on another outbound document; reconcile it manually.',
            $documentId,
        );
        report(new RuntimeException(sprintf('Outbound document %d: %s', $message->id, $reason)));

        // The failed update left the model filled with the rejected values.
        $message->refresh();
        if ($message->transition(OutboundState::Sending, OutboundState::Unconfirmed, ['failure_message' => $reason], ['attempts' => $claim])) {
            event(new DocumentUnconfirmed($message));

            return;
        }

        // The claim already expired into unconfirmed: keep the accepted id on the row.
        $message->transition(OutboundState::Unconfirmed, OutboundState::Unconfirmed, ['failure_message' => $reason], ['attempts' => $claim]);
    }

    /**
     * Applies the send policy to an exception thrown by the transport.
     */
    private function sendFailed(OutboundMessage $message, Throwable $e, int $claim, bool $synchronous): void
    {
        // Definitely not accepted, and trying again would not help. A document that cannot be
        // read is refused by validation, here or in a custom driver, before any request is made.
        $category = match (true) {
            $e instanceof InvalidDocument, $e instanceof DocumentRejected,
            $e instanceof UnsupportedDocument, $e instanceof UnsafeXml => FailureCategory::Validation,
            $e instanceof RecipientUnreachable => $e->category,
            $e instanceof AuthenticationFailed, $e instanceof InvalidArgumentException => FailureCategory::Other,
            default => null,
        };
        if ($category !== null) {
            $this->failSend($message, $claim, $category, $e->getMessage(), true);

            return;
        }

        // Definitely not accepted, and trying again later may help.
        if ($e instanceof TransportException && $e->isRetryable()) {
            if ($synchronous) {
                $this->failSend($message, $claim, FailureCategory::Transport, sprintf(
                    '%s The send was not retried: retries need an asynchronous queue connection.',
                    $e->getMessage(),
                ), false);
            } elseif ($claim < self::setting('sending.max_attempts', 5)) {
                $this->requeue($message, $claim, $e);
            } else {
                $this->failSend($message, $claim, FailureCategory::Transport, $e->getMessage(), false);
            }

            return;
        }

        // The document may have been accepted.
        if ($e instanceof TransportException) {
            $this->unconfirmed($message, $claim, $e->getMessage());

            return;
        }

        report($e);
        $this->unconfirmed($message, $claim, sprintf(
            'The send ended with an unexpected %s; check the access point before resending.',
            $e::class,
        ));
    }

    /**
     * Fails a document the transport did not accept. A definite answer also settles a row whose
     * claim already expired into unconfirmed; a retryable one does not, since it would otherwise
     * have been retried and an operator now owns the row.
     */
    private function failSend(OutboundMessage $message, int $claim, FailureCategory $category, string $reason, bool $definite): void
    {
        $attributes = [
            'failure_category' => $category,
            'failure_message' => $reason,
            'provider_code' => null,
            'finished_at' => now(),
        ];
        $recorded = $definite
            ? $this->settle($message, $claim, OutboundState::Failed, $attributes)
            : $message->transition(OutboundState::Sending, OutboundState::Failed, $attributes, ['attempts' => $claim]);

        if ($recorded) {
            event(new DocumentFailed($message));

            return;
        }

        self::reportLostAnswer($message, $claim, $reason);
    }

    /**
     * Returns the claim to the queue and schedules the next attempt.
     */
    private function requeue(OutboundMessage $message, int $claim, TransportException $e): void
    {
        if (! $message->transition(OutboundState::Sending, OutboundState::Queued, ['failure_message' => $e->getMessage()], ['attempts' => $claim])) {
            self::reportLostAnswer($message, $claim, $e->getMessage());

            return;
        }

        $delay = $e instanceof RateLimited && $e->retryAfterSeconds !== null
            ? $e->retryAfterSeconds
            : self::retryDelay($claim);

        self::schedule($message->id, max(0, $delay), connection: $this->queueConnection());
    }

    /**
     * Reports a definite "not accepted" answer that could not be recorded because the row left
     * this claim meanwhile, typically into unconfirmed. The operator who now owns the row learns
     * that the document was not delivered under this claim.
     */
    private static function reportLostAnswer(OutboundMessage $message, int $claim, string $reason): void
    {
        report(new RuntimeException(sprintf(
            'Outbound document %d was not accepted by its transport under attempt %d, but the row had left that sending claim, so the answer was not recorded: %s',
            $message->id,
            $claim,
            $reason,
        )));
    }

    private function unconfirmed(OutboundMessage $message, int $claim, string $reason): void
    {
        if ($message->transition(OutboundState::Sending, OutboundState::Unconfirmed, ['failure_message' => $reason], ['attempts' => $claim])) {
            event(new DocumentUnconfirmed($message));
        }
    }

    /**
     * Moves the row out of this worker's claim: from sending, or from unconfirmed when the claim
     * expired while the request was in flight. Either way only while the row still carries the
     * claim's attempt count, so a newer claim is never overwritten.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function settle(OutboundMessage $message, int $claim, OutboundState $to, array $attributes): bool
    {
        return $message->transition(OutboundState::Sending, $to, $attributes, ['attempts' => $claim])
            || $message->transition(OutboundState::Unconfirmed, $to, $attributes, ['attempts' => $claim]);
    }

    /**
     * Seconds to wait before the attempt after the given one; the last configured delay repeats.
     */
    private static function retryDelay(int $attempt): int
    {
        return self::delayAt('sending.backoff', self::DEFAULT_BACKOFF, $attempt - 1);
    }

    /**
     * Fails a queued document before it is claimed: one that cannot be rebuilt from its row or
     * that failed validation. Nothing was sent.
     */
    private function failUnsent(OutboundMessage $message, FailureCategory $category, string $reason): void
    {
        if ($message->transition(OutboundState::Queued, OutboundState::Failed, [
            'failure_category' => $category,
            'failure_message' => $reason,
            'finished_at' => now(),
        ])) {
            event(new DocumentFailed($message));
        }
    }
}
