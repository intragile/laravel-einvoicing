<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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
use IntrAgile\EInvoicing\Spain\Verifactu\ChainKey;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\ChainConflict;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\InvalidRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\Verifactu\RecordMapper;
use IntrAgile\EInvoicing\Spain\Verifactu\RegistrationRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\VerifactuQr;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\ChillerlanQrDecoder;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\CrossCheckResult;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\Difference;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\TaxReport;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\VerifactuCrossCheck;
use IntrAgile\EInvoicing\Transport\DeliveryState;
use IntrAgile\EInvoicing\Transport\Exception\TransportException;
use IntrAgile\EInvoicing\Validation\Spain\VerifactuSchemas;
use InvalidArgumentException;

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
 * The record is registered (accepted_with_errors for registered_with_errors) and
 * VerifactuRecordRegistered fires, unless the cross-check fails in the strict mode: the record is
 * then failed, but stays attached, since AEAT holds it and the next record links to it. A failed
 * cross-check fires VerifactuCrossCheckFailed in either mode. A record that cannot be read, or
 * does not link to its chain, is failed without being attached.
 *
 * B2Brouter prints the production ValidarQR host, in its sandbox too, so the cross-check and the
 * stored QR URL use the production host; the chain key keeps the record's environment.
 *
 * The job is never retried by the queue: every later poll is a new delayed job.
 */
final class PollVerifactuTaxReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** Seconds the worker may run the job. */
    public ?int $timeout = null;

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
            || $row->b2brouter_invoice_id === null) {
            return;
        }

        try {
            $settings = $manager->settings();
            $schemas = $manager->schemas();
            $report = $clients->b2brouter()->awaitRegistered($row->b2brouter_invoice_id);
        } catch (TransportException|UnsupportedDocument|InvalidArgumentException|ConfigurationException $exception) {
            $this->later($row, $manager, 'Polling the B2Brouter tax report failed: '.$exception->getMessage());

            return;
        }

        if ($report === null || ! $report->isFinal()) {
            if ($report !== null) {
                $row->b2brouter_report_id = $report->id;
            }
            $this->later($row, $manager, $report === null
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
     * Polls again after the configured delay, or gives up after the last run.
     */
    private function later(VerifactuRecord $row, VerifactuManager $manager, string $reason): void
    {
        try {
            $settings = $manager->settings();
            [$attempts, $delay] = [$settings->pollAttempts, $settings->pollDelay];
        } catch (ConfigurationException) {
            [$attempts, $delay] = [1, 0];
        }

        $runs = $this->attempt + 1;
        if ($runs < $attempts) {
            $row->error_message = self::cut($reason);
            $row->save();
            self::schedule($row->id, $delay, $runs);

            return;
        }

        $row->error_message = self::cut(sprintf('The registration is not known after %d polls of B2Brouter: %s Poll it again later.', $runs, $reason));
        $row->save();
        VerifactuRecordUnconfirmed::dispatch($row);
    }

    private function rejected(VerifactuRecord $row, TaxReport $report, ?string $errors): void
    {
        $message = self::cut(sprintf(
            'B2Brouter tax report %s ended in state %s%s',
            $report->id,
            $report->state,
            $errors === null ? '.' : ': '.$errors,
        ));
        $row->forceFill([
            'state' => VerifactuRecordState::Failed,
            'b2brouter_report_id' => $report->id,
            'error_code' => self::cut($report->state, 32),
            'error_message' => $message,
        ])->save();

        VerifactuRecordRejected::dispatch($row, $row->error_code, $message);
    }

    /**
     * Cross-checks the registered record and attaches it to its B2Brouter chain.
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
            $result = $check->verify($report, null, SourceInvoice::of($row));
            $this->failed($row, $report, $result, 'unreadable_record', 'The record of the B2Brouter tax report cannot be read: '.$exception->getMessage());

            return;
        }

        $store = $manager->store();
        $key = new ChainKey($record->invoiceId()->issuer, $record->system()->installationNumber, $row->environment);
        $result = $check->verify($report, $this->previous($store, $key), SourceInvoice::of($row));

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
                'cross_check' => self::crossCheck($result),
                'error_code' => $strictFailure ? 'cross_check' : ($withErrors ? self::REGISTERED_WITH_ERRORS : null),
                'error_message' => $strictFailure ? self::failureText($result) : null,
            ]);
        } catch (ChainConflict|InvalidArgumentException $exception) {
            $this->failed($row, $report, $result, 'chain_conflict', 'The record of the B2Brouter tax report cannot join its chain: '.$exception->getMessage());

            return;
        }

        if (! $strictFailure) {
            VerifactuRecordRegistered::dispatch($row);
        }
        if (! $result->passed()) {
            VerifactuCrossCheckFailed::dispatch($row, $result);
        }
    }

    /**
     * Fails a delivered record that cannot be attached to its chain.
     */
    private function failed(VerifactuRecord $row, TaxReport $report, CrossCheckResult $result, string $code, string $message): void
    {
        $row->forceFill([
            'state' => VerifactuRecordState::Failed,
            'b2brouter_report_id' => $report->id,
            'cross_check' => [...self::crossCheck($result), 'passed' => false, 'error' => $message],
            'error_code' => $code,
            'error_message' => self::cut($message),
        ])->save();

        VerifactuCrossCheckFailed::dispatch($row, $result);
    }

    /**
     * The stored head record of the chain as a tax report, or null when the chain has no record
     * yet (or only a seed).
     */
    private function previous(DatabaseChainStore $store, ChainKey $key): ?TaxReport
    {
        $chain = $store->chain($key);
        if ($chain === null || $chain->sequence < 1) {
            return null;
        }
        $head = VerifactuRecord::query()
            ->where('chain_id', $chain->id)
            ->where('sequence', $chain->sequence)
            ->first();
        if ($head === null || $head->xml === null) {
            return null;
        }

        return new TaxReport((string) $head->b2brouter_report_id, 'registered', $head->hash, null, null, $head->xml, []);
    }

    /**
     * @return array{passed: bool, summary: string, failures: list<array<string, string>>, known_divergences: list<array<string, string>>}
     */
    private static function crossCheck(CrossCheckResult $result): array
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
