<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Jobs;

use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordKind;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordState;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordAccepted;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordRejected;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordUnconfirmed;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuSubmissionBlocked;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuChain;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuThrottle;
use IntrAgile\EInvoicing\Laravel\Support\ConfigValue;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuClients;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuManager;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\BillingRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\InvalidRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\UnknownIssuer;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerContext;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\Verifactu\Submission;
use IntrAgile\EInvoicing\Spain\Verifactu\VerifactuIssuerResolver;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;
use IntrAgile\EInvoicing\Syntax\Verifactu\RecordReader;
use IntrAgile\EInvoicing\Transport\Aeat\AeatClient;
use IntrAgile\EInvoicing\Transport\Aeat\AeatErrors;
use IntrAgile\EInvoicing\Transport\Aeat\ErrorAction;
use IntrAgile\EInvoicing\Transport\Aeat\FlowControl;
use IntrAgile\EInvoicing\Transport\Aeat\RecordOutcome;
use IntrAgile\EInvoicing\Transport\Aeat\SubmissionResponse;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleState;
use IntrAgile\EInvoicing\Transport\Exception\AuthenticationFailed;
use IntrAgile\EInvoicing\Transport\Exception\DocumentRejected;
use IntrAgile\EInvoicing\Transport\Exception\OutcomeUnknown;
use IntrAgile\EInvoicing\Transport\Exception\RateLimited;
use IntrAgile\EInvoicing\Transport\Exception\TransportUnavailable;
use InvalidArgumentException;
use Throwable;

/**
 * Submits the due VERI*FACTU records of one AEAT throttle key (an installation, or an issuer of an
 * installation) under the AEAT flow control: at most one request per run, of one issuer.
 *
 * The job carries only the throttle key. A run reads the throttle row and stops while it is
 * blocked. Due records are the generated ones and the unconfirmed ones whose backoff
 * (einvoicing.sending.backoff) has elapsed and whose attempts (einvoicing.sending.max_attempts)
 * are not used up. When the flow control allows a send, the records of the issuer with the oldest
 * due record are read from their stored XML, so the bytes written are the bytes sent, in chain
 * order and cut to submission.max_batch, and before a second record of the same invoice and kind
 * (AEAT answers by IDFactura and operation); the flow control's wait is skipped only when that cut
 * holds 1,000 records. Under a lock on the throttle row the flow control is checked again, each
 * record is claimed with a guarded update and the attempt is recorded, so concurrent runs never
 * send a record twice and a concurrent run waits for its turn.
 *
 * AEAT's answer is mapped in one transaction; the rest is scheduled after it commits, and then
 * the events fire. If storing
 * the answer fails, the records become unconfirmed and are resent with the same bytes; AEAT then
 * answers with a duplicate, which accepts them without a CSV. A request without an answer leaves
 * them unconfirmed as well. A submission refused as a whole (a non-transient Client fault or an
 * authentication fault) registered nothing: its records keep their state and the code, and the
 * throttle row is blocked until an operator clears it, as it is when the issuer cannot be
 * resolved or used. Rate limiting and failures before the request leave the records as they were.
 *
 * The records left on the AEAT chain of an issuer moved to the B2Brouter driver are sent with the
 * AEAT credentials still configured for it. Without them, that issuer's records are held: they
 * keep their state, carry error_code aeat_credentials, and the run goes on with the other issuers
 * of the key, which is not blocked.
 *
 * The job is never retried by the queue and never released: whatever is left is scheduled again as
 * a new delayed job, and the unique lock is freed when a run starts, so a run can schedule its
 * successor. On a queue connection that runs jobs synchronously nothing is scheduled from inside a
 * run.
 */
final class SubmitVerifactuRecords implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** Seconds the worker may run the job. */
    public ?int $timeout = null;

    /** The longest delay, in seconds, that Amazon SQS accepts for a message. */
    private const SQS_MAX_DELAY = 900;

    /** Seconds added to the longest delay for the unique lock, and to the request timeout for the job. */
    private const MARGIN = 60;

    /** Seconds a run waits at most while only unconfirmed records wait for their backoff. */
    private const MIN_WAKE = 60;

    /** The states a run may claim a record in. */
    private const CLAIMABLE = [VerifactuRecordState::Generated->value, VerifactuRecordState::Unconfirmed->value];

    private const DEFAULT_MAX_ATTEMPTS = 5;

    private const DEFAULT_BACKOFF = [30, 120, 600, 1800];

    /** The error_code of a record whose issuer cannot send it to AEAT now (see hold()). */
    public const HELD_CODE = 'aeat_credentials';

    /** Queue drivers that run a job in the current process and ignore its delay. */
    private const SYNCHRONOUS_DRIVERS = ['sync', 'deferred', 'background'];

    /** @var array<string, true> the issuers whose records this run holds, by NIF */
    private array $held = [];

    public function __construct(public readonly string $throttleKey)
    {
        $this->onConnection(self::configured('einvoicing.verifactu.submission.connection', 'einvoicing.queue.connection'));
        $this->onQueue(self::configured('einvoicing.verifactu.submission.queue', 'einvoicing.queue.name'));
        $this->timeout = self::jobTimeout();
    }

    /**
     * The key of the unique lock: a hash of the throttle key, which may be longer than a cache key
     * column allows.
     */
    public function uniqueId(): string
    {
        return sha1($this->throttleKey);
    }

    /**
     * Seconds the unique lock of a queued job lasts: the longest delay a run schedules its
     * successor with, plus a margin. A longer wait asked by AEAT only lets a second job be queued,
     * which finds nothing to claim.
     */
    public function uniqueFor(): int
    {
        return max(max(self::resendDelays()), self::initialWait(), self::MIN_WAKE) + self::MARGIN;
    }

    /**
     * Queues a run for the throttle key after the given number of seconds, once the open database
     * transaction commits. While a run for the key is queued, the call does nothing. On Amazon SQS
     * the delay is capped at 900 seconds.
     */
    public static function schedule(string $throttleKey, int $delay = 0): void
    {
        $job = new self($throttleKey);
        $job->afterCommit();
        if (self::driverOf($job->connection) === 'sqs') {
            $delay = min($delay, self::SQS_MAX_DELAY);
        }
        if ($delay > 0) {
            $job->delay($delay);
        }

        dispatch($job);
    }

    public function handle(VerifactuClients $clients, VerifactuIssuerResolver $resolver, VerifactuManager $manager): void
    {
        $throttle = VerifactuThrottle::query()->where('key', $this->throttleKey)->first();
        if ($throttle === null || $throttle->blocked_at !== null) {
            return;
        }

        $settings = $manager->settings();
        $flow = new FlowControl(static fn (): DateTimeImmutable => Carbon::now()->toDateTimeImmutable());
        // Until the first attempt, the configured initial wait applies.
        $state = $throttle->last_sent_at === null ? new ThrottleState(null, $settings->initialWait) : $throttle->state();

        $due = $this->dueRecords();
        // A full submission skips the wait only when the request carries 1,000 records.
        $sendable = min(count($due), $settings->maxBatch);
        if (! $flow->decide($state, $sendable)->sendNow) {
            $this->scheduleRest();

            return;
        }

        while (true) {
            $batch = $this->nextBatch($due, $settings->maxBatch);
            if ($batch === []) {
                $this->scheduleRest();

                return;
            }
            try {
                $records = array_map(static fn (VerifactuRecord $row): BillingRecord => (new RecordReader)->read((string) $row->xml), $batch);
            } catch (InvalidRecord|UnsupportedDocument|UnsafeXml $exception) {
                $this->block($throttle, 'invalid_record', 'A stored VERI*FACTU record cannot be read: '.$exception->getMessage());

                return;
            }
            $records = array_slice(FlowControl::batches($records)[0], 0, $settings->maxBatch);
            $batch = array_slice($batch, 0, count($records));

            $issuer = $batch[0]->issuer_nif;
            try {
                $context = $resolver->resolve(Nif::parse($issuer));
                $this->assertEnvironment($context, $settings->environment, $batch[0]);
                try {
                    $context = VerifactuClients::forAeatChain($context);
                } catch (ConfigurationException $exception) {
                    // An issuer moved to B2Brouter without its AEAT credentials: its records wait,
                    // and the key, which may serve other issuers, stays open.
                    $due = $this->hold($issuer, $due, $exception->getMessage());
                    $sendable = min(count($due), $settings->maxBatch);
                    if ($due === [] || ! $flow->decide($state, $sendable)->sendNow) {
                        $this->scheduleRest();

                        return;
                    }

                    continue;
                }
                $client = $clients->aeat($context);
            } catch (UnknownIssuer $exception) {
                $this->block($throttle, 'unknown_issuer', $exception->getMessage());

                return;
            } catch (ConfigurationException|InvalidArgumentException $exception) {
                $this->block($throttle, 'configuration', $exception->getMessage());

                return;
            } catch (TransportUnavailable) {
                $throttle->store($flow->afterFailure($state, Carbon::now()->toDateTimeImmutable()));
                $this->scheduleRest();

                return;
            }

            break;
        }

        $now = Carbon::now()->toDateTimeImmutable();
        $taken = $this->claimUnderThrottle($batch, $due, $flow, $settings->initialWait, $sendable, $now);
        if ($taken === null) {
            $this->scheduleRest();

            return;
        }
        [$throttle, $state, $claimed] = $taken;
        $records = array_values(array_intersect_key($records, $claimed));
        $claimed = array_values($claimed);

        $this->send($client, $context, $throttle, $flow, $state, $claimed, $records, $now);
    }

    /**
     * Under the lock of the throttle row, checks the flow control again, claims the batch and
     * records the attempt, so two runs that both decided to send cannot both send. The row is
     * written first, which takes the lock on every database (SQLite included) before it is read.
     *
     * @param  list<VerifactuRecord>  $batch
     * @param  list<VerifactuRecord>  $due
     * @return array{VerifactuThrottle, ThrottleState, non-empty-array<int, array{row: VerifactuRecord, state: VerifactuRecordState, attempts: int}>}|null
     *                                                                                                                                                     null when the run must not send now
     */
    private function claimUnderThrottle(array $batch, array $due, FlowControl $flow, int $initialWait, int $sendable, DateTimeImmutable $now): ?array
    {
        return DB::transaction(function () use ($batch, $due, $flow, $initialWait, $sendable, $now): ?array {
            VerifactuThrottle::query()->where('key', $this->throttleKey)->update(['updated_at' => Carbon::now()]);
            $throttle = VerifactuThrottle::query()->where('key', $this->throttleKey)->lockForUpdate()->first();
            if ($throttle === null || $throttle->blocked_at !== null) {
                return null;
            }
            $state = $throttle->last_sent_at === null ? new ThrottleState(null, $initialWait) : $throttle->state();
            if (! $flow->decide($state, $sendable)->sendNow) {
                return null;
            }

            $claimed = $this->claim($batch, $due);
            if ($claimed === []) {
                return null;
            }
            // The attempt is recorded with the claim, so a concurrent run waits for the flow control.
            $throttle->store($flow->afterFailure($state, $now));

            return [$throttle, $state, $claimed];
        });
    }

    /**
     * @param  list<array{row: VerifactuRecord, state: VerifactuRecordState, attempts: int}>  $claimed
     * @param  list<BillingRecord>  $records
     */
    private function send(AeatClient $client, IssuerContext $context, VerifactuThrottle $throttle, FlowControl $flow, ThrottleState $state, array $claimed, array $records, DateTimeImmutable $now): void
    {
        try {
            $submission = new Submission($context->header(), $records);
        } catch (InvalidRecord $exception) {
            $this->restore($claimed, 'invalid_record', $exception->getMessage());
            $this->block($throttle, 'invalid_record', $exception->getMessage());

            return;
        } catch (Throwable $exception) {
            // Nothing was sent: the records keep their state and their attempts.
            $this->restore($claimed);
            $this->scheduleRest();

            throw $exception;
        }

        try {
            $response = $client->submit($submission);
        } catch (OutcomeUnknown) {
            $this->unconfirm($claimed, $now);
            $this->scheduleRest();

            return;
        } catch (RateLimited $exception) {
            $this->restore($claimed);
            $throttle->store(new ThrottleState($now, max($state->waitSeconds, $exception->retryAfterSeconds ?? $state->waitSeconds)));
            $this->scheduleRest();

            return;
        } catch (AuthenticationFailed|DocumentRejected $exception) {
            $code = AeatErrors::codeOf($exception);
            $blockCode = $code === null ? ($exception instanceof AuthenticationFailed ? 'auth' : 'rejected') : (string) $code;
            $this->restore($claimed, $blockCode, $exception->getMessage(), $code === null ? null : AeatErrors::classify($code)->action->name);
            $this->block($throttle, $blockCode, $exception->getMessage());

            return;
        } catch (InvalidRecord $exception) {
            $this->restore($claimed, 'invalid_record', $exception->getMessage());
            $this->block($throttle, 'invalid_record', $exception->getMessage());

            return;
        } catch (Throwable $exception) {
            // Whether the request left is not known, so the records are resent with the same bytes.
            $this->unconfirm($claimed, $now);
            $this->scheduleRest();

            throw $exception;
        }

        foreach ($response->unmatched($submission) as $line) {
            Log::warning('AEAT answered a line that matches no record of the submission; it was ignored.', [
                'throttle_key' => $this->throttleKey,
                'issuer' => $line->invoiceId->issuer->value,
                'number' => $line->invoiceId->number,
                'issue_date' => $line->invoiceId->dateText(),
                'operation' => $line->operation,
                'state' => $line->state->value,
            ]);
        }

        try {
            $events = DB::transaction(fn (): array => $this->record($submission, $response, $claimed, $records, $throttle, $flow, $state, $now));
        } catch (Throwable $exception) {
            // The answer could not be stored: the next run resends the same bytes, and AEAT's
            // duplicate answer accepts the records then.
            $this->unconfirm($claimed, $now);
            VerifactuThrottle::query()->whereKey($throttle->id)->first()?->store($flow->afterResponse($state, $response, $now));
            $this->scheduleRest();

            throw $exception;
        }

        // Scheduled first, so a listener that throws cannot cancel the next run.
        $this->scheduleRest();
        foreach ($events as $event) {
            event($event);
        }
    }

    /**
     * Stores AEAT's answer for each record and the wait it asked for, under the lock of the
     * throttle row.
     *
     * @param  list<array{row: VerifactuRecord, state: VerifactuRecordState, attempts: int}>  $claimed
     * @param  list<BillingRecord>  $records
     * @return list<object> the events to fire once the transaction commits
     */
    private function record(Submission $submission, SubmissionResponse $response, array $claimed, array $records, VerifactuThrottle $throttle, FlowControl $flow, ThrottleState $state, DateTimeImmutable $now): array
    {
        // The throttle row is locked first, as claimUnderThrottle() does, so this transaction and
        // a concurrent claim take their locks in the same order and cannot deadlock.
        VerifactuThrottle::query()->whereKey($throttle->id)->update(['updated_at' => Carbon::now()]);
        VerifactuThrottle::query()->whereKey($throttle->id)->lockForUpdate()->first();

        $outcomes = $response->outcomes($submission);
        $maxAttempts = self::maxAttempts();
        $events = [];

        foreach ($claimed as $index => ['row' => $row, 'attempts' => $attempts]) {
            $outcome = $outcomes[$index];
            $resend = $attempts > 0;
            $line = $response->lineFor($records[$index]);
            $reached = VerifactuRecordState::fromOutcome($outcome, $row->kind === VerifactuRecordKind::Cancellation, $resend, $line?->duplicateState);
            // The code of a confirmed duplicate only says that AEAT already held the record.
            $confirmedDuplicate = in_array($outcome, [RecordOutcome::AlreadyRegistered, RecordOutcome::AlreadyCancelled], true)
                && ($reached === VerifactuRecordState::Accepted || $reached === VerifactuRecordState::AcceptedWithErrors);
            $code = $confirmedDuplicate ? null : $line?->errorCode;

            $row->state = $reached;
            if ($outcome === RecordOutcome::Accepted || $outcome === RecordOutcome::AcceptedWithErrors) {
                $row->csv = $response->csv;
            }
            $row->error_code = $code === null ? null : (string) $code;
            $row->error_message = $code === null ? null : $line->errorDescription;
            $row->error_action = $code === null ? null : AeatErrors::classify($code)->action->name;
            if ($outcome === RecordOutcome::AlreadyRegistered && ! $resend) {
                // AEAT holds another record for this invoice; only a lookup can tell.
                $row->error_action = ErrorAction::ResolveDuplicate->name;
            }
            $row->submitted_at = $now;
            $row->attempts = $attempts + 1;
            $row->save();

            $events[] = match ($reached) {
                VerifactuRecordState::Accepted, VerifactuRecordState::AcceptedWithErrors => new VerifactuRecordAccepted($row, $reached === VerifactuRecordState::AcceptedWithErrors, $row->error_code),
                VerifactuRecordState::Rejected => new VerifactuRecordRejected($row, $row->error_code, $row->error_message),
                default => $row->attempts >= $maxAttempts || $row->error_action === ErrorAction::ResolveDuplicate->name ? new VerifactuRecordUnconfirmed($row) : null,
            };
        }

        $throttle->store($flow->afterResponse($state, $response, $now));

        return array_values(array_filter($events));
    }

    /**
     * The due records on the open AEAT chains of the throttle key, without their XML.
     *
     * @return list<VerifactuRecord>
     */
    private function dueRecords(): array
    {
        $now = Carbon::now()->getTimestamp();

        return array_values(array_filter(
            $this->pendingRecords(),
            static fn (VerifactuRecord $row): bool => self::dueAt($row) <= $now,
        ));
    }

    /**
     * The records that a later run will send: generated ones, and unconfirmed ones that are still
     * resent automatically.
     *
     * @return list<VerifactuRecord>
     */
    private function pendingRecords(): array
    {
        $chains = VerifactuChain::query()
            ->where('throttle_key', $this->throttleKey)
            ->where('driver', IssuerDriver::Aeat->value)
            ->whereNull('closed_at')
            ->pluck('id');
        if ($chains->isEmpty()) {
            return [];
        }

        $maxAttempts = self::maxAttempts();

        return array_values(VerifactuRecord::query()
            ->whereIn('chain_id', $chains)
            ->where('driver', IssuerDriver::Aeat->value)
            ->whereIn('state', [VerifactuRecordState::Generated->value, VerifactuRecordState::Unconfirmed->value])
            ->get(['id', 'chain_id', 'sequence', 'issuer_nif', 'state', 'attempts', 'error_action', 'submitted_at', 'created_at'])
            ->filter(static fn (VerifactuRecord $row): bool => $row->state === VerifactuRecordState::Generated
                || ($row->attempts < $maxAttempts && $row->error_action !== ErrorAction::ResolveDuplicate->name))
            ->all());
    }

    /**
     * When a pending record is due, as a Unix timestamp: at once when generated, after the backoff
     * of its last attempt when unconfirmed.
     */
    private static function dueAt(VerifactuRecord $row): int
    {
        if ($row->state === VerifactuRecordState::Generated || $row->submitted_at === null) {
            return 0;
        }
        $backoff = self::resendDelays();

        return $row->submitted_at->getTimestamp() + $backoff[min(max($row->attempts - 1, 0), count($backoff) - 1)];
    }

    /**
     * The records of the next submission, with their XML: the issuer of the oldest due record
     * first, then chain order, at most max_batch records, ending before a second record of the
     * same invoice and kind. Rows another run has taken meanwhile are left out.
     *
     * @param  list<VerifactuRecord>  $due
     * @return list<VerifactuRecord>
     */
    private function nextBatch(array $due, int $maxBatch): array
    {
        $oldest = [];
        foreach ($due as $row) {
            $created = $row->created_at->getTimestamp();
            $oldest[$row->issuer_nif] = min($oldest[$row->issuer_nif] ?? $created, $created);
        }
        uksort($oldest, static fn (string $a, string $b): int => [$oldest[$a], $a] <=> [$oldest[$b], $b]);
        $issuer = (string) array_key_first($oldest);

        $ids = [];
        foreach ($due as $row) {
            if ($row->issuer_nif === $issuer) {
                $ids[] = $row->id;
            }
        }

        $rows = VerifactuRecord::query()
            ->whereIn('id', $ids)
            ->whereIn('state', self::CLAIMABLE)
            ->orderBy('chain_id')
            ->orderBy('sequence')
            ->limit(min($maxBatch, FlowControl::MAX_RECORDS))
            ->get()
            ->all();

        // AEAT answers each line by IDFactura and operation, so a second record of the same
        // invoice and kind (a correction written before the first record left) waits for the
        // next submission.
        $batch = [];
        $seen = [];
        foreach ($rows as $row) {
            $key = implode("\0", [$row->number, substr($row->issue_date, 0, 10), $row->kind->value]);
            if (isset($seen[$key])) {
                break;
            }
            $seen[$key] = true;
            $batch[] = $row;
        }

        return $batch;
    }

    /**
     * Claims the rows for this run: each changes to sending only if it is still generated or
     * unconfirmed, in the state and at the attempt it was found due in. Another run that read the
     * same rows claims none of those this run changed.
     *
     * @param  list<VerifactuRecord>  $batch
     * @param  list<VerifactuRecord>  $due  the rows as they were found due
     * @return array<int, array{row: VerifactuRecord, state: VerifactuRecordState, attempts: int}> by position in the batch
     */
    private function claim(array $batch, array $due): array
    {
        $read = [];
        foreach ($due as $row) {
            $read[$row->id] = [$row->state, $row->attempts];
        }

        return DB::transaction(static function () use ($batch, $read): array {
            $claimed = [];
            foreach ($batch as $index => $row) {
                [$state, $attempts] = $read[$row->id] ?? [null, null];
                if ($state === null || ! in_array($state->value, self::CLAIMABLE, true)) {
                    continue;
                }
                $changed = VerifactuRecord::query()
                    ->whereKey($row->id)
                    ->where('state', $state->value)
                    ->where('attempts', $attempts)
                    ->update(['state' => VerifactuRecordState::Sending->value, 'updated_at' => Carbon::now()]);
                if ($changed === 1) {
                    $claimed[$index] = ['row' => $row, 'state' => $state, 'attempts' => (int) $attempts];
                    $row->state = VerifactuRecordState::Sending;
                    $row->attempts = (int) $attempts;
                    $row->syncOriginal();
                }
            }

            return $claimed;
        });
    }

    /**
     * Returns claimed records to the state they were claimed in; AEAT registered nothing.
     *
     * @param  list<array{row: VerifactuRecord, state: VerifactuRecordState, attempts: int}>  $claimed
     */
    private function restore(array $claimed, ?string $code = null, ?string $message = null, ?string $action = null): void
    {
        foreach ($claimed as ['row' => $row, 'state' => $state]) {
            $values = ['state' => $state->value, 'updated_at' => Carbon::now()];
            if ($code !== null) {
                $values += ['error_code' => mb_substr($code, 0, 32), 'error_message' => $message, 'error_action' => $action];
            }
            VerifactuRecord::query()->whereKey($row->id)->where('state', VerifactuRecordState::Sending->value)->update($values);
        }
    }

    /**
     * Marks claimed records unconfirmed after an attempt whose outcome is not known, and reports
     * those whose attempts are used up.
     *
     * @param  list<array{row: VerifactuRecord, state: VerifactuRecordState, attempts: int}>  $claimed
     */
    private function unconfirm(array $claimed, DateTimeImmutable $attemptedAt): void
    {
        $maxAttempts = self::maxAttempts();
        $submittedAt = Carbon::instance($attemptedAt)->setTimezone(date_default_timezone_get());
        foreach ($claimed as ['row' => $row, 'attempts' => $attempts]) {
            $changed = VerifactuRecord::query()->whereKey($row->id)->where('state', VerifactuRecordState::Sending->value)->update([
                'state' => VerifactuRecordState::Unconfirmed->value,
                'attempts' => $attempts + 1,
                'submitted_at' => $submittedAt,
                'updated_at' => Carbon::now(),
            ]);
            if ($changed === 1 && $attempts + 1 >= $maxAttempts && ($fresh = $row->fresh()) !== null) {
                event(new VerifactuRecordUnconfirmed($fresh));
            }
        }
    }

    /**
     * Leaves the due records of an issuer that cannot send for this run: they keep their state and
     * attempts and carry the reason, and nothing is scheduled for them alone. The next run of the
     * key, or einvoicing:verifactu-submit, tries them again.
     *
     * @param  list<VerifactuRecord>  $due
     * @return list<VerifactuRecord> the due records of the other issuers
     */
    private function hold(string $issuer, array $due, string $message): array
    {
        $this->held[$issuer] = true;
        $ids = [];
        $rest = [];
        foreach ($due as $row) {
            if ($row->issuer_nif === $issuer) {
                $ids[] = $row->id;
            } else {
                $rest[] = $row;
            }
        }

        VerifactuRecord::query()
            ->whereIn('id', $ids)
            ->whereIn('state', self::CLAIMABLE)
            ->update(['error_code' => self::HELD_CODE, 'error_message' => $message, 'updated_at' => Carbon::now()]);
        Log::warning('VERI*FACTU records of an issuer are held: they cannot be sent to AEAT.', [
            'throttle_key' => $this->throttleKey,
            'issuer' => $issuer,
            'records' => count($ids),
            'reason' => $message,
        ]);

        return $rest;
    }

    /**
     * Blocks the throttle key until an operator clears it, and reports it.
     */
    private function block(VerifactuThrottle $throttle, string $code, string $message): void
    {
        $throttle->blocked_at = Carbon::now();
        $throttle->blocked_code = mb_substr($code, 0, 32);
        $throttle->blocked_message = $message;
        $throttle->save();

        event(new VerifactuSubmissionBlocked($this->throttleKey, $code, $message));
    }

    /**
     * Schedules the next run when records are pending: when the flow control allows the next send,
     * at once while a full submission is due, and no later than the throttle wait while only
     * unconfirmed records wait for their backoff (so a newly generated record is not held up by a
     * long backoff, since a schedule made while a run is queued is dropped).
     */
    private function scheduleRest(): void
    {
        $throttle = VerifactuThrottle::query()->where('key', $this->throttleKey)->first();
        if ($throttle === null || $throttle->blocked_at !== null || $this->runsSynchronously()) {
            return;
        }
        // Records of an issuer held by this run are not a reason to run again.
        $pending = array_values(array_filter($this->pendingRecords(), fn (VerifactuRecord $row): bool => ! isset($this->held[$row->issuer_nif])));
        if ($pending === []) {
            return;
        }

        $now = Carbon::now()->getTimestamp();
        $throttleDelay = max(0, ($throttle->next_send_at?->getTimestamp() ?? $now) - $now);
        $generated = count(array_filter($pending, static fn (VerifactuRecord $row): bool => $row->state === VerifactuRecordState::Generated));
        if (min($generated, self::maxBatch()) >= FlowControl::MAX_RECORDS) {
            $delay = 0;
        } elseif ($generated > 0) {
            $delay = $throttleDelay;
        } else {
            $earliest = min(array_map(self::dueAt(...), $pending));
            $delay = max($throttleDelay, min(max(0, $earliest - $now), max($throttle->wait_seconds, self::MIN_WAKE)));
        }

        self::schedule($this->throttleKey, $delay);
    }

    /**
     * @throws ConfigurationException when the issuer's environment is not the configured one, or
     *                                not the environment of the records
     */
    private function assertEnvironment(IssuerContext $context, VerifactuEnvironment $configured, VerifactuRecord $row): void
    {
        if ($context->environment !== $configured || $context->environment !== $row->environment) {
            throw new ConfigurationException(sprintf(
                'The issuer resolver returned issuer %s for the %s environment, but einvoicing.verifactu.environment is %s and the queued records are for %s.',
                $context->nif->value,
                $context->environment->value,
                $configured->value,
                $row->environment->value,
            ));
        }
    }

    /** Whether this run's queue connection runs jobs in the current process and ignores delays. */
    private function runsSynchronously(): bool
    {
        $connection = $this->job?->getConnectionName() ?? $this->connection;

        return in_array(self::driverOf(is_string($connection) ? $connection : null), self::SYNCHRONOUS_DRIVERS, true);
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

    /** The AEAT request timeout plus a margin for reading and storing the records. */
    private static function jobTimeout(): int
    {
        return self::jobTimeoutFor(config('einvoicing.verifactu.aeat.timeout'));
    }

    /**
     * Seconds a run may take for an einvoicing.verifactu.aeat.timeout value: the request timeout
     * (30 s when unset or invalid) plus a margin for reading and storing the records.
     */
    public static function jobTimeoutFor(mixed $timeout): int
    {
        return (int) ceil(is_numeric($timeout) && $timeout > 0 ? (float) $timeout : 30.0) + self::MARGIN;
    }

    /**
     * The attempts after which an unconfirmed record is no longer resent automatically
     * (einvoicing.sending.max_attempts).
     */
    public static function maxAttempts(): int
    {
        return max(1, (int) ceil(ConfigValue::number(config('einvoicing.sending.max_attempts') ?? self::DEFAULT_MAX_ATTEMPTS, 'einvoicing.sending.max_attempts')));
    }

    private static function maxBatch(): int
    {
        return ConfigValue::integer(config('einvoicing.verifactu.submission.max_batch') ?? FlowControl::MAX_RECORDS, 'einvoicing.verifactu.submission.max_batch', 1, FlowControl::MAX_RECORDS);
    }

    private static function initialWait(): int
    {
        $wait = config('einvoicing.verifactu.submission.initial_wait');

        return is_numeric($wait) ? max(0, (int) ceil((float) $wait)) : FlowControl::INITIAL_WAIT_SECONDS;
    }

    /**
     * The configured resend delays in seconds; the last one repeats.
     *
     * @return non-empty-list<int>
     */
    private static function resendDelays(): array
    {
        $configured = config('einvoicing.sending.backoff');
        $delays = is_array($configured)
            ? array_values(array_map(static fn (mixed $delay): int => max(0, (int) ceil((float) $delay)), array_filter($configured, is_numeric(...))))
            : [];

        return $delays === [] ? self::DEFAULT_BACKOFF : $delays;
    }
}
