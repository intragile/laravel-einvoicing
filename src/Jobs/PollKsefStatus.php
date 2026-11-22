<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Jobs;

use DateTimeImmutable;
use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentDelivered;
use IntrAgile\EInvoicing\Laravel\Events\DocumentFailed;
use IntrAgile\EInvoicing\Laravel\Events\DocumentUnconfirmed;
use IntrAgile\EInvoicing\Laravel\Ksef\KsefInvoiceFacts;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Support\ConfigFlag;
use IntrAgile\EInvoicing\Transport\DeliveryFailure;
use IntrAgile\EInvoicing\Transport\DeliveryState;
use IntrAgile\EInvoicing\Transport\Exception\RateLimited;
use IntrAgile\EInvoicing\Transport\Exception\TransportException;
use IntrAgile\EInvoicing\Transport\FailureCategory;
use IntrAgile\EInvoicing\Transport\Ksef\KsefTransport;
use IntrAgile\EInvoicing\Transport\Ksef\Session\InvoicingMode;
use IntrAgile\EInvoicing\Transport\Ksef\Session\KsefInvoiceStatus;
use InvalidArgumentException;
use LogicException;

/**
 * Reads the KSeF status of a sent invoice, and fetches its receipt (UPO) once KSeF has accepted
 * it.
 *
 * On an asynchronous queue connection the job reads the status once per run; the runs follow
 * status.delays and stop after status.max_attempts, as PollDeliveryStatus does, and every run, a
 * failed one included, counts. A RateLimited answer delays the next run by its Retry-After. On a
 * synchronous connection (sync, deferred, background), which cannot delay jobs, the one run waits
 * for the final status within the KSeF client's polling bounds (KsefTransport::awaitFinal()) and
 * tries the receipt once.
 *
 * Status 200 delivers the row with its KSeF number and the invoicing mode KSeF reports; an invoice
 * KSeF classifies as offline gets its offline24 deadline when the row has none. The UPO is then
 * fetched into the row; when KSeF has not issued it yet, later runs fetch it. A final failure
 * fails the row and fires DocumentFailed, as does a document id that is not a KSeF one.
 *
 * KSeF reports a duplicate (status 440) when an invoice with the same seller NIP, invoice type and
 * number is already accepted, whatever its content. The row is delivered under the original KSeF
 * number only when KsefTransport::isSameDocument() confirms, through the original's receipt, that
 * the original is this exact file; the original's receipt is then stored and provider_code is
 * "440". When the original is another invoice the row fails with category duplicate. When it
 * cannot be decided (the receipt is refused or not issued yet), the next run asks again; once no
 * run is left the row becomes unconfirmed for an operator, never delivered.
 */
final class PollKsefStatus extends MessageJob
{
    /** The provider_code of a row delivered as an earlier accepted copy of the same file. */
    public const DUPLICATE = '440';

    private const DUPLICATE_STATUS = 440;

    private const DEFAULT_DELAYS = [60, 300, 900, 3600, 21600];

    private const DEFAULT_MAX_CHECKS = 10;

    /**
     * Schedules the first run for an invoice KSeF has just accepted for processing, unless polling
     * is disabled. On a synchronous connection the run happens at once and waits for the final
     * status.
     */
    public static function start(int $messageId, int $attempts, ?string $connection = null): void
    {
        if (! ConfigFlag::enabled('status.poll')) {
            return;
        }

        self::schedule($messageId, self::delayAt('status.delays', self::DEFAULT_DELAYS, 0), $attempts, $connection);
    }

    /**
     * Up to four requests (status, receipt, and the original's receipt for a duplicate), each with
     * its timeouts, plus a margin.
     */
    protected static function jobTimeout(): int
    {
        return 4 * (self::setting('http.timeout', 60) + self::setting('http.connect_timeout', 10)) + 30;
    }

    public function handle(EInvoicingManager $manager): void
    {
        self::assertSettings();

        $message = OutboundMessage::query()->find($this->messageId);
        if ($message === null
            || $message->transport !== EInvoicingManager::KSEF
            || $message->attempts !== $this->attempt
            || $message->provider_document_id === null
            || (! self::awaitsStatus($message) && ! self::awaitsReceipt($message))) {
            return;
        }
        $documentId = $message->provider_document_id;

        $maxChecks = self::setting('status.max_attempts', self::DEFAULT_MAX_CHECKS);
        $checks = $message->status_checks;
        if ($checks >= $maxChecks) {
            return;
        }

        // A configuration problem propagates before the check is counted.
        $transport = $manager->ksef()->transport();

        if (! $message->transition($message->state, $message->state, ['status_checks' => $checks + 1], [
            'status_checks' => $checks,
            'attempts' => $this->attempt,
            'provider_document_id' => $documentId,
        ])) {
            return;
        }
        $checks++;
        $last = $checks >= $maxChecks || self::runsSynchronously($this->queueConnection());

        if (self::awaitsStatus($message)) {
            if (! self::isKsefDocumentId($documentId)) {
                $this->failRow($message, $documentId, new DeliveryFailure(FailureCategory::Other, sprintf(
                    'The KSeF status cannot be read: the provider_document_id "%s" is not a KSeF document id "<session reference>/<invoice reference>"; resolve the row with the id send() returned.',
                    $documentId,
                )));

                return;
            }
            try {
                $status = self::runsSynchronously($this->queueConnection())
                    ? $transport->awaitFinal($documentId)
                    : $transport->invoiceStatus($documentId);
            } catch (TransportException $e) {
                $this->again($message, $checks, $maxChecks, $e);

                return;
            }
            $recorded = $status->deliveryState() === DeliveryState::Failed && $status->code === self::DUPLICATE_STATUS
                ? $this->duplicate($transport, $message, $documentId, $status, $last)
                : $this->record($message, $documentId, $status);
            if (! $recorded) {
                $this->again($message, $checks, $maxChecks);

                return;
            }
        }

        if (self::awaitsReceipt($message)) {
            $this->fetchReceipt($transport, $message, $documentId, $checks, $maxChecks);
        }
    }

    /**
     * Records a status read; false while the invoice is still being processed or the row has
     * moved on.
     */
    private function record(OutboundMessage $message, string $documentId, KsefInvoiceStatus $status): bool
    {
        return match ($status->deliveryState()) {
            DeliveryState::Delivered => $status->ksefNumber !== null
                && $this->deliver($message, $documentId, $status->ksefNumber, $status->invoicingMode),
            DeliveryState::Failed => $this->failRow($message, $documentId, $status->failure()),
            DeliveryState::Pending, DeliveryState::Unknown => false,
        };
    }

    private function deliver(OutboundMessage $message, string $documentId, string $ksefNumber, ?InvoicingMode $mode, ?string $providerCode = null, ?string $upo = null): bool
    {
        $attributes = [
            'ksef_number' => $ksefNumber,
            'failure_category' => null,
            'failure_message' => null,
            'provider_code' => $providerCode,
            'finished_at' => now(),
        ];
        if ($mode !== null) {
            $attributes['invoicing_mode'] = $mode->value;
        }
        if ($mode === InvoicingMode::Offline && $message->offline_deadline === null) {
            $attributes['offline_deadline'] = self::offlineDeadline($message);
        }
        if ($upo !== null) {
            $attributes['upo'] = $upo;
        }

        if (! $message->transition(OutboundState::Pending, OutboundState::Delivered, $attributes, ['provider_document_id' => $documentId])) {
            return false;
        }
        event(new DocumentDelivered($message));

        return true;
    }

    /**
     * Settles a duplicate by comparing the original with this row's file; false when it cannot be
     * decided yet and another run is left.
     *
     * @param  bool  $last  whether this is the last run, after which an undecided duplicate becomes unconfirmed
     */
    private function duplicate(KsefTransport $transport, OutboundMessage $message, string $documentId, KsefInvoiceStatus $status, bool $last): bool
    {
        $original = $status->originalKsefNumber;
        $failure = $status->failure() ?? new DeliveryFailure(FailureCategory::Duplicate, $status->description, (string) $status->code);
        $session = $status->originalSessionReferenceNumber;

        try {
            $same = $transport->isSameDocument($status, $message->xml);
        } catch (LogicException) {
            // KSeF named no original to compare with, so no later run can decide it.
            return $this->unconfirmed($message, $documentId, sprintf(
                '%s KSeF names no original KSeF number and session to compare this invoice with; confirm in KSeF whether it was accepted and run einvoicing:resolve.',
                $failure->message,
            ));
        } catch (TransportException|UnsafeXml|UnsupportedDocument $e) {
            if (! $last) {
                return false;
            }

            return $this->unconfirmed($message, $documentId, sprintf(
                'KSeF reports this invoice as a duplicate of the KSeF invoice %s, but its receipt (UPO) could not be compared with this file: %s Confirm in KSeF whether %s is this invoice and run einvoicing:resolve.',
                $original ?? 'named in the status',
                rtrim($e->getMessage(), '.').'.',
                $original ?? 'the original',
            ));
        }

        if ($same && $original !== null) {
            $upo = null;
            if ($session !== null) {
                try {
                    $upo = $transport->upoByKsefNumber($session, $original)->xml;
                } catch (TransportException|InvalidArgumentException|UnsafeXml|UnsupportedDocument) {
                    // The receipt was just read for the comparison; a second failure leaves it out.
                }
            }

            return $this->deliver($message, $documentId, $original, null, self::DUPLICATE, $upo);
        }

        return $this->failRow($message, $documentId, new DeliveryFailure(
            FailureCategory::Duplicate,
            sprintf(
                '%s The KSeF invoice %s has the same seller NIP, invoice type and number (P_2) but is a different file: this invoice was not accepted.',
                $failure->message,
                $original,
            ),
            $failure->providerCode,
        ));
    }

    private function failRow(OutboundMessage $message, string $documentId, ?DeliveryFailure $failure): bool
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

    private function unconfirmed(OutboundMessage $message, string $documentId, string $reason): bool
    {
        if (! $message->transition(OutboundState::Pending, OutboundState::Unconfirmed, [
            'failure_message' => $reason,
            'provider_code' => (string) self::DUPLICATE_STATUS,
        ], ['provider_document_id' => $documentId])) {
            return false;
        }
        event(new DocumentUnconfirmed($message));

        return true;
    }

    private function fetchReceipt(KsefTransport $transport, OutboundMessage $message, string $documentId, int $checks, int $maxChecks): void
    {
        try {
            $upo = $transport->upo($documentId);
        } catch (TransportException $e) {
            $this->again($message, $checks, $maxChecks, $e);

            return;
        } catch (LogicException|UnsafeXml|UnsupportedDocument) {
            $this->again($message, $checks, $maxChecks);

            return;
        }

        $message->transition(OutboundState::Delivered, OutboundState::Delivered, ['upo' => $upo->xml], [
            'provider_document_id' => $documentId,
            'attempts' => $this->attempt,
        ]);
    }

    /**
     * Schedules the next run while the row still waits for its status or receipt and checks
     * remain; after a RateLimited answer no sooner than its Retry-After.
     */
    private function again(OutboundMessage $message, int $checks, int $maxChecks, ?TransportException $e = null): void
    {
        $connection = $this->queueConnection();
        if ((! self::awaitsStatus($message) && ! self::awaitsReceipt($message))
            || $checks >= $maxChecks
            || self::runsSynchronously($connection)) {
            return;
        }

        $delay = self::delayAt('status.delays', self::DEFAULT_DELAYS, $checks);
        if ($e instanceof RateLimited && $e->retryAfterSeconds !== null) {
            $delay = $e->retryAfterSeconds;
        }

        self::schedule($message->id, $delay, $this->attempt, $connection);
    }

    /**
     * Whether the id has the shape of a KSeF document id, "<session reference>/<invoice reference>".
     */
    public static function isKsefDocumentId(string $documentId): bool
    {
        return preg_match('#\A[A-Za-z0-9-]{1,64}/[A-Za-z0-9-]{1,64}\z#', $documentId) === 1;
    }

    private static function awaitsStatus(OutboundMessage $message): bool
    {
        return $message->state === OutboundState::Pending;
    }

    private static function awaitsReceipt(OutboundMessage $message): bool
    {
        return $message->state === OutboundState::Delivered
            && $message->upo === null
            && $message->ksef_number !== null
            && $message->provider_code !== self::DUPLICATE;
    }

    /**
     * The offline24 deadline of the row's invoice, or null when its issue date cannot be read.
     */
    private static function offlineDeadline(OutboundMessage $message): ?DateTimeImmutable
    {
        try {
            return KsefInvoiceFacts::read($message->xml)->offlineDeadline()->deadline;
        } catch (InvalidArgumentException|UnsafeXml|UnsupportedDocument) {
            return null;
        }
    }
}
