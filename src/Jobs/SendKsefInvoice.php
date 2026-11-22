<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Jobs;

use Illuminate\Database\UniqueConstraintViolationException;
use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentFailed;
use IntrAgile\EInvoicing\Laravel\Events\DocumentSent;
use IntrAgile\EInvoicing\Laravel\Events\DocumentUnconfirmed;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Transport\Exception\AuthenticationFailed;
use IntrAgile\EInvoicing\Transport\Exception\DocumentRejected;
use IntrAgile\EInvoicing\Transport\Exception\RateLimited;
use IntrAgile\EInvoicing\Transport\Exception\TransportException;
use IntrAgile\EInvoicing\Transport\FailureCategory;
use IntrAgile\EInvoicing\Transport\SendResult;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Sends a queued FA(3) invoice to KSeF at most once per claim, in the online session of the
 * worker's KSeF transport.
 *
 * The claim, the claim expiry and the backoff are those of SendDocument. Unlike a Peppol send, a
 * send whose outcome is not known is repeated: KSeF answers a copy of an invoice it has already
 * accepted with status 440 and the original KSeF number, and PollKsefStatus delivers the row
 * under that number once the original's receipt proves it is the same file. The row is marked
 * with provider_code "outcome_unknown" while an earlier send may have been accepted, so that a
 * later refusal of this row makes it unconfirmed rather than failed. Once sending.max_attempts are
 * used up with the outcome still unknown, the row becomes unconfirmed. An answer that arrives after
 * the claim was ended by ExpireSendingClaim is reported, as SendDocument does.
 *
 * The FA(3) file is validated by the transport before anything is sent; an invalid file fails the
 * row with category validation. An accepted invoice becomes pending and PollKsefStatus reads its
 * KSeF status.
 */
final class SendKsefInvoice extends MessageJob
{
    /** The document type of a KSeF row. */
    public const DOCUMENT_TYPE = 'FA (3)';

    /** The invoicing_mode of a row issued in, or classified by KSeF as, offline mode. */
    public const MODE_OFFLINE = 'Offline';

    public const MODE_ONLINE = 'Online';

    /** The provider_code of a row an earlier send of which may have been accepted. */
    public const OUTCOME_UNKNOWN = 'outcome_unknown';

    private const DEFAULT_BACKOFF = [30, 120, 600, 1800];

    /**
     * Authentication, opening the session and the send, each with its timeouts, plus validation.
     */
    protected static function jobTimeout(): int
    {
        return 3 * (self::setting('http.timeout', 60) + self::setting('http.connect_timeout', 10)) + 30;
    }

    /**
     * Seconds after which a sending claim is over: the job's timeout plus a margin.
     */
    public static function claimLifetime(): int
    {
        return self::jobTimeout() + 30;
    }

    public function handle(EInvoicingManager $manager): void
    {
        self::assertSettings();

        $message = OutboundMessage::query()->find($this->messageId);
        if ($message === null || $message->state !== OutboundState::Queued || $message->transport !== EInvoicingManager::KSEF) {
            return;
        }

        // A configuration problem propagates and leaves the row queued.
        $transport = $manager->ksef()->transport();

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
            $result = $transport->send($message->xml, $message->invoicing_mode === self::MODE_OFFLINE);
        } catch (Throwable $e) {
            $this->sendFailed($message, $e, $claim, $synchronous);

            return;
        }

        $this->accepted($message, $result, $claim);
    }

    private function accepted(OutboundMessage $message, SendResult $result, int $claim): void
    {
        try {
            $recorded = $this->settle($message, $claim, OutboundState::Pending, [
                'provider_document_id' => $result->documentId,
                'failure_category' => null,
                'failure_message' => null,
                'sent_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $reason = sprintf(
                'KSeF accepted the invoice as %s, but that document id is already recorded on another outbound document; reconcile it manually.',
                $result->documentId,
            );
            report(new RuntimeException(sprintf('Outbound document %d: %s', $message->id, $reason)));
            $message->refresh();
            $this->unconfirmed($message, $claim, $reason);

            return;
        }

        if (! $recorded) {
            report(new RuntimeException(sprintf(
                'Outbound document %d was accepted by KSeF as %s after its sending claim ended; reconcile it manually.',
                $message->id,
                $result->documentId,
            )));

            return;
        }

        event(new DocumentSent($message));
        PollKsefStatus::start($message->id, $claim, $this->queueConnection());
    }

    /**
     * Applies the send policy to an exception thrown by the transport.
     */
    private function sendFailed(OutboundMessage $message, Throwable $e, int $claim, bool $synchronous): void
    {
        // Definitely not accepted, and trying again would not help.
        $category = match (true) {
            $e instanceof DocumentRejected, $e instanceof UnsupportedDocument, $e instanceof UnsafeXml => FailureCategory::Validation,
            $e instanceof AuthenticationFailed, $e instanceof InvalidArgumentException => FailureCategory::Other,
            default => null,
        };
        // An earlier send of these bytes may have been accepted. Validation is decided by the
        // bytes alone, so an invalid file was never accepted; any other refusal settles only
        // this attempt.
        $earlierMayBeAccepted = $message->provider_code === self::OUTCOME_UNKNOWN;
        if ($category !== null && $earlierMayBeAccepted && $category !== FailureCategory::Validation) {
            $this->unconfirmed($message, $claim, $e->getMessage().' An earlier send of this invoice may have been accepted; check it in KSeF.');

            return;
        }
        if ($category !== null) {
            $this->failRow($message, $claim, $category, $e->getMessage());

            return;
        }

        if (! $e instanceof TransportException) {
            report($e);
            $this->unconfirmed($message, $claim, sprintf(
                'The send ended with an unexpected %s; check the invoice in KSeF before resending.',
                $e::class,
            ));

            return;
        }

        // Definitely not accepted (retryable), or possibly accepted: a resend is safe either way,
        // since KSeF answers a copy of an accepted invoice as a duplicate.
        $mayBeAccepted = ! $e->isRetryable();
        if ($synchronous || $claim >= self::setting('sending.max_attempts', 5)) {
            $suffix = $synchronous ? ' The send was not repeated: repeats need an asynchronous queue connection.' : '';
            if ($mayBeAccepted || $earlierMayBeAccepted) {
                $this->unconfirmed($message, $claim, $e->getMessage().$suffix);
            } else {
                $this->failRow($message, $claim, FailureCategory::Transport, $e->getMessage().$suffix);
            }

            return;
        }

        $this->requeue($message, $claim, $e, $mayBeAccepted);
    }

    /**
     * Returns the claim to the queue and schedules the next attempt.
     */
    private function requeue(OutboundMessage $message, int $claim, TransportException $e, bool $mayBeAccepted): void
    {
        $attributes = ['failure_message' => $e->getMessage()];
        if ($mayBeAccepted) {
            $attributes['provider_code'] = self::OUTCOME_UNKNOWN;
        }
        if (! $message->transition(OutboundState::Sending, OutboundState::Queued, $attributes, ['attempts' => $claim])) {
            if ($mayBeAccepted) {
                // Keep the marker on a row the claim expiry made unconfirmed meanwhile.
                $message->transition(OutboundState::Unconfirmed, OutboundState::Unconfirmed, ['provider_code' => self::OUTCOME_UNKNOWN], ['attempts' => $claim]);
            }
            self::reportLostAnswer($message, $claim, $e->getMessage());

            return;
        }

        $delay = $e instanceof RateLimited && $e->retryAfterSeconds !== null
            ? $e->retryAfterSeconds
            : self::delayAt('sending.backoff', self::DEFAULT_BACKOFF, $claim - 1);

        self::schedule($message->id, max(0, $delay), connection: $this->queueConnection());
    }

    private function failRow(OutboundMessage $message, int $claim, FailureCategory $category, string $reason): void
    {
        if ($this->settle($message, $claim, OutboundState::Failed, [
            'failure_category' => $category,
            'failure_message' => $reason,
            'provider_code' => null,
            'finished_at' => now(),
        ])) {
            event(new DocumentFailed($message));

            return;
        }
        self::reportLostAnswer($message, $claim, $reason);
    }

    private function unconfirmed(OutboundMessage $message, int $claim, string $reason): void
    {
        $attributes = ['failure_message' => $reason, 'provider_code' => self::OUTCOME_UNKNOWN];
        if ($message->transition(OutboundState::Sending, OutboundState::Unconfirmed, $attributes, ['attempts' => $claim])) {
            event(new DocumentUnconfirmed($message));

            return;
        }
        // The claim expiry made the row unconfirmed meanwhile: keep this attempt's reason on it.
        $message->transition(OutboundState::Unconfirmed, OutboundState::Unconfirmed, $attributes, ['attempts' => $claim]);
        self::reportLostAnswer($message, $claim, $reason);
    }

    /**
     * Reports an answer that could not be recorded because the row left this sending claim
     * meanwhile, typically into unconfirmed through ExpireSendingClaim, so the operator who now
     * owns the row learns what happened under this claim.
     */
    private static function reportLostAnswer(OutboundMessage $message, int $claim, string $reason): void
    {
        report(new RuntimeException(sprintf(
            'KSeF outbound document %d answered attempt %d after the row had left that sending claim, so the answer was not recorded as such: %s',
            $message->id,
            $claim,
            $reason,
        )));
    }

    /**
     * Moves the row out of this worker's claim: from sending, or from unconfirmed when the claim
     * expired while the request was in flight; only while the row still carries the claim's
     * attempt count.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function settle(OutboundMessage $message, int $claim, OutboundState $to, array $attributes): bool
    {
        return $message->transition(OutboundState::Sending, $to, $attributes, ['attempts' => $claim])
            || $message->transition(OutboundState::Unconfirmed, $to, $attributes, ['attempts' => $claim]);
    }
}
