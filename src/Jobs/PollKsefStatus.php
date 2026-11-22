<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Jobs;

use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentDelivered;
use IntrAgile\EInvoicing\Laravel\Events\DocumentFailed;
use IntrAgile\EInvoicing\Laravel\Ksef\KsefInvoiceFacts;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Support\ConfigFlag;
use IntrAgile\EInvoicing\Transport\DeliveryFailure;
use IntrAgile\EInvoicing\Transport\DeliveryState;
use IntrAgile\EInvoicing\Transport\Exception\TransportException;
use IntrAgile\EInvoicing\Transport\FailureCategory;
use IntrAgile\EInvoicing\Transport\Ksef\KsefTransport;
use IntrAgile\EInvoicing\Transport\Ksef\Session\InvoicingMode;
use IntrAgile\EInvoicing\Transport\Ksef\Session\KsefInvoiceStatus;
use InvalidArgumentException;
use LogicException;

/**
 * Reads the KSeF status of a sent invoice once per run, and fetches its receipt (UPO) once KSeF
 * has accepted it.
 *
 * The runs follow status.delays and stop after status.max_attempts, as PollDeliveryStatus does;
 * every run, a failed one included, counts. Status 200 delivers the row with its KSeF number and
 * the invoicing mode KSeF reports; an invoice KSeF classifies as offline gets its offline24
 * deadline when the row has none. The UPO is then fetched into the row; when KSeF has not issued
 * it yet, later runs fetch it. A final failure fails the row and fires DocumentFailed.
 *
 * A duplicate (status 440) is delivered with the original KSeF number when the original is known
 * to carry the same bytes: either another stored KSeF row with that number holds an identical
 * file, or no stored row holds the number and an earlier send of this row may have been accepted
 * (provider_code "outcome_unknown", see SendKsefInvoice). Any other duplicate fails with category
 * duplicate. A duplicate delivered this way keeps provider_code "440" and gets no UPO here: the
 * receipt belongs to the original send.
 */
final class PollKsefStatus extends MessageJob
{
    /** The provider_code of a row delivered as a duplicate of an earlier copy. */
    public const DUPLICATE = '440';

    private const DUPLICATE_STATUS = 440;

    private const DEFAULT_DELAYS = [60, 300, 900, 3600, 21600];

    private const DEFAULT_MAX_CHECKS = 10;

    /**
     * Schedules the first run for an invoice KSeF has just accepted for processing, unless polling
     * is disabled or the queue connection cannot delay jobs.
     */
    public static function start(int $messageId, int $attempts, ?string $connection = null): void
    {
        if (! ConfigFlag::enabled('status.poll') || self::runsSynchronously($connection)) {
            return;
        }

        self::schedule($messageId, self::delayAt('status.delays', self::DEFAULT_DELAYS, 0), $attempts, $connection);
    }

    /**
     * Up to three requests (status, and status with receipt), each with its timeouts, plus a margin.
     */
    protected static function jobTimeout(): int
    {
        return 3 * (self::setting('http.timeout', 60) + self::setting('http.connect_timeout', 10)) + 30;
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

        if (self::awaitsStatus($message)) {
            try {
                $status = $transport->invoiceStatus($documentId);
            } catch (TransportException) {
                $this->again($message, $checks, $maxChecks);

                return;
            }
            if (! $this->record($message, $documentId, $status)) {
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
            DeliveryState::Failed => $status->code === self::DUPLICATE_STATUS
                ? $this->duplicate($message, $documentId, $status)
                : $this->failRow($message, $documentId, $status->failure()),
            DeliveryState::Pending, DeliveryState::Unknown => false,
        };
    }

    private function deliver(OutboundMessage $message, string $documentId, string $ksefNumber, ?InvoicingMode $mode, ?string $providerCode = null): bool
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

        if (! $message->transition(OutboundState::Pending, OutboundState::Delivered, $attributes, ['provider_document_id' => $documentId])) {
            return false;
        }
        event(new DocumentDelivered($message));

        return true;
    }

    /**
     * Settles a duplicate: delivered when the original carries this row's bytes, failed otherwise.
     */
    private function duplicate(OutboundMessage $message, string $documentId, KsefInvoiceStatus $status): bool
    {
        $original = $status->originalKsefNumber;
        $failure = $status->failure() ?? new DeliveryFailure(FailureCategory::Duplicate, $status->description, (string) $status->code);
        if ($original === null) {
            return $this->failRow($message, $documentId, $failure);
        }

        $hash = hash('sha256', $message->xml);
        $stored = OutboundMessage::query()
            ->where('transport', EInvoicingManager::KSEF)
            ->where('ksef_number', $original)
            ->whereKeyNot($message->getKey())
            ->get(['id', 'xml']);
        if ($stored->isNotEmpty()) {
            $identical = $stored->contains(static fn (OutboundMessage $row): bool => hash_equals(hash('sha256', $row->xml), $hash));
            if ($identical) {
                return $this->deliver($message, $documentId, $original, null, self::DUPLICATE);
            }

            return $this->failRow($message, $documentId, new DeliveryFailure(
                FailureCategory::Duplicate,
                sprintf('%s The stored outbound document %d holds that KSeF number with a different file.', $failure->message, $stored->first()->id),
                $failure->providerCode,
            ));
        }

        if ($message->provider_code === SendKsefInvoice::OUTCOME_UNKNOWN) {
            return $this->deliver($message, $documentId, $original, null, self::DUPLICATE);
        }

        return $this->failRow($message, $documentId, new DeliveryFailure(
            FailureCategory::Duplicate,
            sprintf('%s No earlier send of this invoice may have been accepted, so the original was sent elsewhere; compare it before resending.', $failure->message),
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

    private function fetchReceipt(KsefTransport $transport, OutboundMessage $message, string $documentId, int $checks, int $maxChecks): void
    {
        try {
            $upo = $transport->upo($documentId);
        } catch (TransportException|LogicException|UnsafeXml|UnsupportedDocument) {
            $this->again($message, $checks, $maxChecks);

            return;
        }

        $message->transition(OutboundState::Delivered, OutboundState::Delivered, ['upo' => $upo->xml], [
            'provider_document_id' => $documentId,
            'attempts' => $this->attempt,
        ]);
    }

    /**
     * Schedules the next run while the row still waits for its status or receipt and checks remain.
     */
    private function again(OutboundMessage $message, int $checks, int $maxChecks): void
    {
        $connection = $this->queueConnection();
        if ((! self::awaitsStatus($message) && ! self::awaitsReceipt($message))
            || $checks >= $maxChecks
            || self::runsSynchronously($connection)) {
            return;
        }

        self::schedule($message->id, self::delayAt('status.delays', self::DEFAULT_DELAYS, $checks), $this->attempt, $connection);
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
    private static function offlineDeadline(OutboundMessage $message): ?\DateTimeImmutable
    {
        try {
            return KsefInvoiceFacts::read($message->xml)->offlineDeadline()->deadline;
        } catch (InvalidArgumentException|UnsafeXml|UnsupportedDocument) {
            return null;
        }
    }
}
