<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordState;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuCrossCheckFailed;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordRegistered;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordRejected;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordUnconfirmed;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Laravel\Verifactu\DatabaseChainStore;
use IntrAgile\EInvoicing\Laravel\Verifactu\SourceInvoice;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuClients;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuManager;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuSettings;
use IntrAgile\EInvoicing\Spain\Verifactu\BillingRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainHead;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainKey;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\ChainConflict;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\InvalidRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\Verifactu\RecordMapper;
use IntrAgile\EInvoicing\Spain\Verifactu\RegistrationRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\VerifactuQr;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\CheckStatus;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\ChillerlanQrDecoder;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\CrossCheckResult;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\CrossCheckStep;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\Difference;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\TaxReport;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\VerifactuCrossCheck;
use IntrAgile\EInvoicing\Transport\DeliveryState;
use IntrAgile\EInvoicing\Transport\Exception\TransportException;
use IntrAgile\EInvoicing\Validation\Spain\VerifactuSchemas;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

/**
 * Polls the B2Brouter tax report of one imported VERI*FACTU record until it is final, then settles
 * the record.
 *
 * One run waits for the report within the client's polling bound (15 reads 3 s apart by default,
 * about 45 s). A report that is not final yet, or a read that failed, is polled again by a new job
 * after einvoicing.verifactu.b2brouter.poll_delay seconds, for poll_attempts runs in all; after the
 * last one the record stays pending and VerifactuRecordUnconfirmed fires.
 *
 * A final report is settled by its delivery state:
 * - failed (refused, invalid, error, annulled): the record is failed with the report state and
 *   its errors, joins no chain, and VerifactuRecordRejected fires;
 * - delivered (registered, or registered_with_errors, AEAT's AceptadoConErrores): the record
 *   B2Brouter wrote is attached to its B2Brouter chain with its XML, its Huella and the QR URL
 *   B2Brouter prints, and is cross-checked against our own engine (VerifactuCrossCheck). The
 *   previous report of the check is rebuilt from the stored predecessor in the chain, never read
 *   through B2Brouter's opaque previous_id.
 *
 * Records attach in chain order, as the records themselves state it. A registered record that does
 * not link to the chain head (or would seed an empty chain) while the invoice its RegistroAnterior
 * names is a pending B2Brouter row of the same issuer and environment waits: it stays pending with
 * error_code awaiting_predecessor, without using up its polls, and is polled again once a record of
 * the issuer settles (attached, rejected or failed). Row ids play no part. A record whose named
 * predecessor is not pending is attempted against the head (seeding an empty chain from its link);
 * if it does not link it is failed and left out of the chain, which then stays blocked until an
 * operator acts.
 *
 * The record is registered (accepted_with_errors for registered_with_errors) and
 * VerifactuRecordRegistered fires, unless the cross-check fails in the strict mode: the record is
 * then failed, but stays attached, since AEAT holds it and the next record links to it. A failed
 * cross-check fires VerifactuCrossCheckFailed in either mode. An invoice source that cannot be read
 * back counts as a failed cross-check.
 *
 * Every final write is conditional on the row still being pending and unattached, so a duplicate
 * or concurrent run of the same record changes nothing and fires no event. A run that fails with
 * an unexpected exception stores the reason and fires VerifactuRecordUnconfirmed; the record stays
 * pending and is not polled again automatically.
 *
 * B2Brouter prints the production ValidarQR host, in its sandbox too, so the cross-check and the
 * stored QR URL use the production host; the chain key keeps the record's environment. The
 * installation number of a B2Brouter chain is the one B2Brouter writes into its records, which
 * B2Brouter derives from the account id: chain rows, error messages and logs that name the chain
 * carry it.
 *
 * The job is never retried by the queue: every later poll is a new delayed job.
 */
final class PollVerifactuTaxReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** Seconds the worker may run the job. */
    public ?int $timeout = null;

    /** The error_code of a record that waits for the pending predecessor it names. */
    public const AWAITING_PREDECESSOR = 'awaiting_predecessor';

    /** The longest delay, in seconds, that Amazon SQS accepts for a message. */
    private const SQS_MAX_DELAY = 900;

    /** Seconds added to the polling bound of one run for its requests and the settling. */
    private const MARGIN = 30;

    /** The state of a final report that AEAT registered with errors. */
    private const REGISTERED_WITH_ERRORS = 'registered_with_errors';

    /** Bytes of the failure text stored as error_message. */
    private const MESSAGE_BYTES = 1000;

    public function __construct(public readonly int $recordId, public readonly int $attempt = 0)
    {
        $this->onConnection(self::configured('einvoicing.verifactu.submission.connection', 'einvoicing.queue.connection'));
        $this->onQueue(self::configured('einvoicing.verifactu.submission.queue', 'einvoicing.queue.name'));
        $this->timeout = (int) ceil(VerifactuClients::POLL_ATTEMPTS * VerifactuClients::POLL_WAIT_MS / 1000) + self::MARGIN;
    }

    /**
     * Queues a poll of the record after the given number of seconds, once the open database
     * transaction commits. On Amazon SQS the delay is capped at 900 seconds.
     *
     * @param  int  $attempt  the number of runs before this one
     */
    public static function schedule(int $recordId, int $delay = 0, int $attempt = 0): void
    {
        $job = new self($recordId, $attempt);
        $job->afterCommit();
        if (self::driverOf($job->connection) === 'sqs') {
            $delay = min($delay, self::SQS_MAX_DELAY);
        }
        if ($delay > 0) {
            $job->delay($delay);
        }

        dispatch($job);
    }

    public function handle(VerifactuClients $clients, VerifactuManager $manager): void
    {
        $row = VerifactuRecord::query()->find($this->recordId);
        if ($row === null
            || $row->driver !== IssuerDriver::B2Brouter
            || $row->state !== VerifactuRecordState::Pending
            || $row->chain_id !== null
            || $row->b2brouter_invoice_id === null) {
            return;
        }

        try {
            $settings = $manager->settings();
            $schemas = $manager->schemas();
            $report = $clients->b2brouter()->awaitRegistered($row->b2brouter_invoice_id);
        } catch (TransportException|UnsupportedDocument|InvalidArgumentException|ConfigurationException $exception) {
            $this->later($row, $manager, null, 'Polling the B2Brouter tax report failed: '.$exception->getMessage());

            return;
        }

        if ($report === null || ! $report->isFinal()) {
            $this->later($row, $manager, $report?->id, $report === null
                ? 'B2Brouter lists no tax report for the invoice yet.'
                : sprintf('The B2Brouter tax report %s is %s.', $report->id, $report->state));

            return;
        }

        $status = $report->deliveryStatus();
        if ($status->state !== DeliveryState::Delivered) {
            $this->rejected($row, $report, $status->failure?->message);

            return;
        }

        $this->registered($row, $report, $settings, $schemas, $manager);
    }

    /**
     * Called by the queue when a run ended with an exception: the reason is stored and
     * VerifactuRecordUnconfirmed fires, unless the record was settled meanwhile. The record stays
     * pending; it is polled again only on request.
     */
    public function failed(Throwable $exception): void
    {
        $row = VerifactuRecord::query()->find($this->recordId);
        if ($row === null) {
            return;
        }
        $message = self::cut(sprintf('Polling the B2Brouter tax report failed unexpectedly (%s): %s', $exception::class, $exception->getMessage()));
        if (self::settle($row, ['error_message' => $message])) {
            VerifactuRecordUnconfirmed::dispatch($row);
        }
    }

    /**
     * Polls again after the configured delay, or gives up after the last run.
     */
    private function later(VerifactuRecord $row, VerifactuManager $manager, ?string $reportId, string $reason): void
    {
        try {
            $settings = $manager->settings();
            [$attempts, $delay] = [$settings->pollAttempts, $settings->pollDelay];
        } catch (ConfigurationException) {
            [$attempts, $delay] = [1, 0];
        }

        $columns = $reportId === null ? [] : ['b2brouter_report_id' => $reportId];
        $runs = $this->attempt + 1;
        if ($runs < $attempts) {
            if (self::settle($row, [...$columns, 'error_code' => null, 'error_message' => self::cut($reason)])) {
                self::schedule($row->id, $delay, $runs);
            }

            return;
        }

        $message = self::cut(sprintf('The registration is not known after %d polls of B2Brouter: %s Poll it again later.', $runs, $reason));
        if (self::settle($row, [...$columns, 'error_code' => null, 'error_message' => $message])) {
            VerifactuRecordUnconfirmed::dispatch($row);
        }
    }

    private function rejected(VerifactuRecord $row, TaxReport $report, ?string $errors): void
    {
        $message = self::cut(sprintf(
            'B2Brouter tax report %s ended in state %s%s',
            $report->id,
            $report->state,
            $errors === null ? '.' : ': '.$errors,
        ));
        $code = self::cut($report->state, 32);
        $settled = self::settle($row, [
            'state' => VerifactuRecordState::Failed->value,
            'b2brouter_report_id' => $report->id,
            'error_code' => $code,
            'error_message' => $message,
        ]);
        if (! $settled) {
            return;
        }

        self::wakeSuccessors($row);
        VerifactuRecordRejected::dispatch($row, $code, $message);
    }

    /**
     * Cross-checks the registered record and attaches it to its B2Brouter chain, in chain order.
     */
    private function registered(VerifactuRecord $row, TaxReport $report, VerifactuSettings $settings, VerifactuSchemas $schemas, VerifactuManager $manager): void
    {
        $check = new VerifactuCrossCheck(
            $schemas,
            VerifactuEnvironment::Production,
            ChillerlanQrDecoder::isAvailable() ? new ChillerlanQrDecoder : null,
            new RecordMapper,
        );

        try {
            $record = $report->record();
        } catch (UnsupportedDocument|UnsafeXml|InvalidRecord $exception) {
            // The schema step reports why; nothing can be attached.
            $this->failedUnattached($row, $report, $this->crossCheck($check, $report, null, $row), 'unreadable_record', 'The record of the B2Brouter tax report cannot be read: '.$exception->getMessage());

            return;
        }

        $store = $manager->store();
        $key = new ChainKey($record->invoiceId()->issuer, $record->system()->installationNumber, $row->environment);
        $head = $store->head($key);
        if (! self::links($record, $head) && ($named = self::namedPredecessor($row, $record)) !== null) {
            $this->await($row, $report, $record, $named);

            return;
        }

        $result = $this->crossCheck($check, $report, $this->previous($store, $key, $head), $row);
        $strictFailure = $result->failsIn($settings->crossCheck);
        $withErrors = $report->state === self::REGISTERED_WITH_ERRORS;
        $state = match (true) {
            $strictFailure => VerifactuRecordState::Failed,
            $withErrors => VerifactuRecordState::AcceptedWithErrors,
            default => VerifactuRecordState::Registered,
        };

        try {
            $store->appendForeign($key, $record, (string) $report->recordXml, $row, [
                'state' => $state,
                'qr_url' => $record instanceof RegistrationRecord ? VerifactuQr::url($record, VerifactuEnvironment::Production) : null,
                'b2brouter_report_id' => $report->id,
                'cross_check' => self::crossCheckColumn($result),
                'error_code' => $strictFailure ? 'cross_check' : ($withErrors ? self::REGISTERED_WITH_ERRORS : null),
                'error_message' => $strictFailure ? self::failureText($result) : null,
            ]);
        } catch (ChainConflict|InvalidArgumentException $exception) {
            if (! self::isOpen($row)) {
                // Another run attached or settled the record meanwhile.
                return;
            }
            $this->failedUnattached($row, $report, $result, 'chain_conflict', 'The record of the B2Brouter tax report cannot join its chain: '.$exception->getMessage());

            return;
        }

        // Successors are woken before the events, so a failing listener cannot stall them.
        self::wakeSuccessors($row);
        if (! $strictFailure) {
            VerifactuRecordRegistered::dispatch($row);
        }
        if (! $result->passed()) {
            VerifactuCrossCheckFailed::dispatch($row, $result);
        }
    }

    /**
     * Parks a registered record behind the pending predecessor it names: it stays pending and keeps
     * its polls until a record of its issuer settles. When the predecessor is no longer pending by
     * the time the mark is written, the record is polled again at once.
     */
    private function await(VerifactuRecord $row, TaxReport $report, BillingRecord $record, VerifactuRecord $predecessor): void
    {
        $parked = self::settle($row, [
            'b2brouter_report_id' => $report->id,
            'error_code' => self::AWAITING_PREDECESSOR,
            'error_message' => sprintf(
                'B2Brouter registered the record, but it does not link to the chain head yet: it waits for B2Brouter record row %d, the invoice its RegistroAnterior names, to settle.',
                $predecessor->id,
            ),
        ]);
        if ($parked && self::namedPredecessor($row, $record) === null) {
            self::schedule($row->id, 0, $this->attempt);
        }
    }

    /**
     * Fails a delivered record that cannot be attached to its chain.
     */
    private function failedUnattached(VerifactuRecord $row, TaxReport $report, CrossCheckResult $result, string $code, string $message): void
    {
        $settled = self::settle($row, [
            'state' => VerifactuRecordState::Failed->value,
            'b2brouter_report_id' => $report->id,
            'cross_check' => json_encode([...self::crossCheckColumn($result), 'passed' => false, 'error' => $message], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'error_code' => $code,
            'error_message' => self::cut($message),
        ]);
        if (! $settled) {
            return;
        }

        self::wakeSuccessors($row);
        VerifactuCrossCheckFailed::dispatch($row, $result);
    }

    /**
     * The cross-check result, or a failed semantics step when the invoice source of the row
     * cannot be read back.
     */
    private function crossCheck(VerifactuCrossCheck $check, TaxReport $report, ?TaxReport $previous, VerifactuRecord $row): CrossCheckResult
    {
        try {
            $invoice = SourceInvoice::of($row);
        } catch (UnsupportedDocument|UnsafeXml|UnexpectedValueException $exception) {
            $message = 'The invoice the record was imported from cannot be read back: '.$exception->getMessage();

            return new CrossCheckResult(
                $report->id,
                [CrossCheckStep::Semantics->value => CheckStatus::Failed],
                [new Difference(CrossCheckStep::Semantics, CheckStatus::Failed, 'invoice', '', '', $message)],
                [],
            );
        }

        return $check->verify($report, $previous, $invoice);
    }

    /**
     * The stored head record of the chain as a tax report, or null when the chain has no record
     * yet (or only a seed).
     */
    private function previous(DatabaseChainStore $store, ChainKey $key, ?ChainHead $head): ?TaxReport
    {
        if ($head === null || $head->sequence < 1) {
            return null;
        }
        $chain = $store->chain($key);
        $stored = $chain === null ? null : VerifactuRecord::query()
            ->where('chain_id', $chain->id)
            ->where('sequence', $head->sequence)
            ->first();
        if ($stored === null || $stored->xml === null) {
            return null;
        }

        return new TaxReport((string) $stored->b2brouter_report_id, 'registered', $stored->hash, null, null, $stored->xml, []);
    }

    /** Whether the record links to the head: the first record of an empty chain, or the head's successor. */
    private static function links(BillingRecord $record, ?ChainHead $head): bool
    {
        return $head === null ? $record->chainLink()->isFirst() : $head->isLinkedBy($record);
    }

    /**
     * The pending, unattached B2Brouter row of the record's issuer and environment for the invoice
     * the record names in RegistroAnterior, or null when the record names none or no such row is
     * pending.
     */
    private static function namedPredecessor(VerifactuRecord $row, BillingRecord $record): ?VerifactuRecord
    {
        $previous = $record->chainLink()->previous();
        if ($previous === null || $previous->issuer->value !== $row->issuer_nif) {
            return null;
        }

        return VerifactuRecord::query()
            ->where('issuer_nif', $row->issuer_nif)
            ->where('environment', $row->environment->value)
            ->where('driver', IssuerDriver::B2Brouter->value)
            ->where('state', VerifactuRecordState::Pending->value)
            ->whereNull('chain_id')
            ->where('number', $previous->number)
            ->where('issue_date', $previous->issueDate->format('Y-m-d'))
            ->whereKeyNot($row->id)
            ->orderBy('id')
            ->first();
    }

    /**
     * Polls again the records of the row's issuer and environment that wait for their predecessor;
     * each checks again whether the predecessor it names is still pending.
     */
    private static function wakeSuccessors(VerifactuRecord $row): void
    {
        $waiting = VerifactuRecord::query()
            ->where('issuer_nif', $row->issuer_nif)
            ->where('environment', $row->environment->value)
            ->where('driver', IssuerDriver::B2Brouter->value)
            ->where('state', VerifactuRecordState::Pending->value)
            ->whereNull('chain_id')
            ->where('error_code', self::AWAITING_PREDECESSOR)
            ->whereKeyNot($row->id)
            ->orderBy('id')
            ->get();

        foreach ($waiting as $successor) {
            self::schedule($successor->id);
        }
    }

    /** Whether the row is still pending and unattached in the database. */
    private static function isOpen(VerifactuRecord $row): bool
    {
        return VerifactuRecord::query()
            ->whereKey($row->id)
            ->where('state', VerifactuRecordState::Pending->value)
            ->whereNull('chain_id')
            ->exists();
    }

    /**
     * Writes the columns only while the row is still pending and unattached, then reloads it.
     *
     * @param  array<string, mixed>  $columns  raw column values
     * @return bool whether this run wrote them
     */
    private static function settle(VerifactuRecord $row, array $columns): bool
    {
        $written = VerifactuRecord::query()
            ->whereKey($row->id)
            ->where('state', VerifactuRecordState::Pending->value)
            ->whereNull('chain_id')
            ->toBase()
            ->update([...$columns, 'updated_at' => Carbon::now()]);
        if ($written !== 1) {
            return false;
        }
        $row->refresh();

        return true;
    }

    /**
     * @return array{passed: bool, summary: string, failures: list<array<string, string>>, known_divergences: list<array<string, string>>}
     */
    private static function crossCheckColumn(CrossCheckResult $result): array
    {
        return [
            'passed' => $result->passed(),
            'summary' => $result->summary(),
            'failures' => array_map(self::difference(...), $result->failures()),
            'known_divergences' => array_map(self::difference(...), $result->knownDivergences()),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function difference(Difference $difference): array
    {
        return [
            'step' => $difference->step->name,
            'field' => $difference->field,
            'ours' => $difference->ours,
            'theirs' => $difference->theirs,
            'message' => $difference->message,
        ];
    }

    private static function failureText(CrossCheckResult $result): string
    {
        $fields = array_map(
            static fn (Difference $difference): string => $difference->step->name.' '.$difference->field,
            $result->failures(),
        );

        return self::cut('The B2Brouter record failed the cross-check: '.($fields === [] ? 'a step failed' : implode(', ', $fields)).'.');
    }

    private static function cut(string $text, int $bytes = self::MESSAGE_BYTES): string
    {
        return strlen($text) > $bytes ? mb_strcut($text, 0, $bytes, 'UTF-8') : $text;
    }

    private static function driverOf(?string $connection): ?string
    {
        $connection ??= config('queue.default');
        $driver = is_string($connection) && $connection !== '' ? config("queue.connections.{$connection}.driver") : null;

        return is_string($driver) ? $driver : null;
    }

    /** The first non-empty string of two settings, or null. */
    private static function configured(string $key, string $fallback): ?string
    {
        foreach ([$key, $fallback] as $name) {
            $value = config($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
