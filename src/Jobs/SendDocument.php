<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Jobs;

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

        $synchronous = self::runsSynchronously();
        if (! $synchronous) {
            $this->scheduleExpiry($message, $claim);
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
     * Schedules the end of the claim for a worker that dies mid-send. When that cannot be
     * scheduled, nothing has been sent yet, so the claim is released and the error propagates.
     */
    private function scheduleExpiry(OutboundMessage $message, int $claim): void
    {
        try {
            ExpireSendingClaim::schedule($message->id, self::claimLifetime(), $claim);
        } catch (Throwable $e) {
            $message->transition(OutboundState::Sending, OutboundState::Queued, [], ['attempts' => $claim]);

            throw $e;
        }
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
            PollDeliveryStatus::start($message->id, $claim);
        }
    }

    /**
     * Applies the send policy to an exception thrown by the transport.
     */
    private function sendFailed(OutboundMessage $message, Throwable $e, int $claim, bool $synchronous): void
    {
        // Definitely not accepted, and trying again would not help. A document that cannot be
        // read is refused by validation before any request is made.
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
        }
    }

    /**
     * Returns the claim to the queue and schedules the next attempt.
     */
    private function requeue(OutboundMessage $message, int $claim, TransportException $e): void
    {
        if (! $message->transition(OutboundState::Sending, OutboundState::Queued, ['failure_message' => $e->getMessage()], ['attempts' => $claim])) {
            return;
        }

        $delay = $e instanceof RateLimited && $e->retryAfterSeconds !== null
            ? $e->retryAfterSeconds
            : self::retryDelay($claim);

        self::schedule($message->id, max(0, $delay));
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
