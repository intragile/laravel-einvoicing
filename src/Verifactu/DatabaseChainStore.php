<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Verifactu;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordKind;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordState;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuChain;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuThrottle;
use IntrAgile\EInvoicing\Spain\Verifactu\BillingRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\CancellationRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainHead;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainKey;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainStore;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\ChainConflict;
use IntrAgile\EInvoicing\Spain\Verifactu\InvoiceId;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\Verifactu\RecordTimestamp;
use IntrAgile\EInvoicing\Spain\Verifactu\RegistrationRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\VerifactuQr;
use IntrAgile\EInvoicing\Syntax\Verifactu\RecordWriter;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleKey;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Keeps VERI*FACTU chains in the einvoicing_verifactu_* tables.
 *
 * Every write runs in one transaction (retried on deadlock) that:
 * 1. reads the chain row with a row lock and rebuilds the head from it; on SQLite the row is
 *    inserted (or ignored) first, so the database write lock is held before the read, while on
 *    MySQL and PostgreSQL a missing row is created in a short transaction before this one;
 * 2. inserts the throttle row if it is missing;
 * 3. builds the record and checks that it links to that head;
 * 4. stores the record row with the written XML and the literal timestamp text;
 * 5. moves the head with a compare-and-set on the sequence, so a head moved by any other writer
 *    meanwhile fails the write with ChainConflict instead of forking the chain.
 *
 * The head stores the head invoice's number and issue date; its issuer is the chain's issuer, so
 * a record of another issuer is refused. A closed chain, and a chain of the other driver (records
 * generated here vs records B2Brouter wrote), take no further record.
 */
final class DatabaseChainStore implements ChainStore
{
    /** @var Closure(ChainKey): ThrottleKey */
    private readonly Closure $defaultThrottle;

    private readonly ?string $connectionName;

    /**
     * @param  ThrottleKey|(Closure(ChainKey): ThrottleKey)  $defaultThrottle  the throttle of the chains
     *                                                                         that append() and seed() write
     */
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RecordWriter $writer,
        ThrottleKey|Closure $defaultThrottle,
    ) {
        $this->defaultThrottle = $defaultThrottle instanceof ThrottleKey
            ? static fn (): ThrottleKey => $defaultThrottle
            : $defaultThrottle;
        $this->connectionName = $db instanceof Connection ? $db->getName() : null;
    }

    /**
     * @throws ConfigurationException when the throttle of the chain cannot be derived, such as
     *                                VerifactuManager's store without a declared computer system;
     *                                nothing is written then
     */
    public function append(ChainKey $key, Closure $build): BillingRecord
    {
        return $this->appendRecord($key, IssuerDriver::Aeat, ($this->defaultThrottle)($key), $build, [])[0];
    }

    public function head(ChainKey $key): ?ChainHead
    {
        $row = $this->chainQuery($key)->first();

        return $row === null ? null : self::readChain($row, $key)[2];
    }

    /**
     * @throws ConfigurationException when the throttle of the chain cannot be derived, such as
     *                                VerifactuManager's store without a declared computer system;
     *                                nothing is written then
     */
    public function seed(ChainKey $key, InvoiceId $last, string $fingerprint): ChainHead
    {
        self::assertIssuer($key, $last);
        $head = ChainHead::seeded($last, $fingerprint);
        $throttle = ($this->defaultThrottle)($key);

        $this->prepareChain($key, IssuerDriver::Aeat, $throttle);
        $this->db->transaction(function () use ($key, $head, $throttle): void {
            [$chainId, $sequence, $current] = $this->openChain($key, IssuerDriver::Aeat, $throttle);

            if ($current !== null) {
                throw new ChainConflict(sprintf('Chain %s already has a head and cannot be seeded.', $key->toString()));
            }

            $this->moveHead($key, $chainId, $sequence, 0, ['seeded' => true, ...self::headColumns($head->invoiceId, $head->fingerprint, null)]);
        }, 3);

        return $head;
    }

    /**
     * Builds the next record under the chain lock, stores it as a row and moves the head.
     *
     * The throttle row of $throttle is created if it is missing. $attributes are stored on the
     * record row (e.g. the reference); they cannot replace the chain, hash, XML or invoice columns.
     *
     * @param  Closure(?ChainHead): BillingRecord  $build
     * @param  array<string, mixed>  $attributes
     *
     * @throws ChainConflict when the built record does not link to the head, the head moved, or the
     *                       chain is closed or a B2Brouter chain
     */
    public function appendRow(ChainKey $key, IssuerDriver $driver, ThrottleKey $throttle, Closure $build, array $attributes = []): VerifactuRecord
    {
        return $this->appendRecord($key, $driver, $throttle, $build, $attributes)[1];
    }

    /**
     * Attaches a record written by another service (B2Brouter) to its chain, filling the given
     * pending row; no row is inserted and no throttle is touched. A row that is attached already, or
     * no longer pending (settled by another run), is refused.
     *
     * On an empty chain, a record that carries a RegistroAnterior first seeds the chain from that
     * link; a first record (PrimerRegistro) starts it. $xml is stored as given. $attributes are
     * stored on the row; the state is registered unless they say otherwise.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ChainConflict when the record does not link to the head, the row is already attached or not pending,
     *                       or the chain is closed or an AEAT chain
     * @throws InvalidArgumentException when the stored row belongs to another invoice, environment or driver
     */
    public function appendForeign(ChainKey $key, BillingRecord $record, string $xml, VerifactuRecord $row, array $attributes = []): VerifactuRecord
    {
        self::assertIssuer($key, $record->invoiceId());

        $this->prepareChain($key, IssuerDriver::B2Brouter, null);
        $this->db->transaction(function () use ($key, $record, $xml, $row, $attributes): void {
            [$chainId, $current, $head] = $this->openChain($key, IssuerDriver::B2Brouter, null);

            $target = $this->records()->whereKey($row->id)->whereNull('chain_id')->where('state', VerifactuRecordState::Pending->value)->lockForUpdate()->first();
            if ($target === null) {
                throw new ChainConflict(sprintf('The VERI*FACTU record row %d is already attached to a chain, or no longer pending.', $row->id));
            }
            self::assertRowIsFor($target, $record->invoiceId(), $key);

            $previous = $record->chainLink()->previous();
            if ($head === null && $previous !== null) {
                self::assertIssuer($key, $previous);
                $head = ChainHead::seeded($previous, $record->chainLink()->previousFingerprint());
                $this->moveHead($key, $chainId, $current, 0, ['seeded' => true, ...self::headColumns($head->invoiceId, $head->fingerprint, null)]);
                $current = 0;
            }

            self::assertLinked($key, $record, $head);
            $sequence = $head === null ? 1 : $head->sequence + 1;

            $target->forceFill([
                'state' => VerifactuRecordState::Registered,
                ...$attributes,
                ...self::chainColumns($chainId, $sequence, $record),
                ...self::invoiceColumns($key, IssuerDriver::B2Brouter, $record),
                'xml' => $xml,
            ])->save();

            $this->moveHead($key, $chainId, $current, $sequence, self::headColumns($record->invoiceId(), $record->fingerprint(), $record->generatedAt()->text));
        }, 3);

        return $row->refresh();
    }

    /** The chain row, or null when the chain has never been written. */
    public function chain(ChainKey $key): ?VerifactuChain
    {
        return VerifactuChain::on($this->connectionName)
            ->where('issuer_nif', $key->issuer->value)
            ->where('installation_number', $key->installationNumber)
            ->where('environment', $key->environment->value)
            ->first();
    }

    /**
     * @param  Closure(?ChainHead): BillingRecord  $build
     * @param  array<string, mixed>  $attributes
     * @return array{BillingRecord, VerifactuRecord}
     */
    private function appendRecord(ChainKey $key, IssuerDriver $driver, ThrottleKey $throttle, Closure $build, array $attributes): array
    {
        $this->prepareChain($key, $driver, $throttle);

        return $this->db->transaction(function () use ($key, $driver, $throttle, $build, $attributes): array {
            [$chainId, $current, $head] = $this->openChain($key, $driver, $throttle);

            $record = $build($head);
            self::assertIssuer($key, $record->invoiceId());
            self::assertLinked($key, $record, $head);
            $sequence = $head === null ? 1 : $head->sequence + 1;

            $row = $this->records();
            $row->forceFill([
                'state' => VerifactuRecordState::Generated,
                'attempts' => 0,
                ...$attributes,
                ...self::chainColumns($chainId, $sequence, $record),
                ...self::invoiceColumns($key, $driver, $record),
                'xml' => $this->writer->write($record),
                'qr_url' => $record instanceof RegistrationRecord ? VerifactuQr::url($record, $key->environment) : null,
            ])->save();

            $this->moveHead($key, $chainId, $current, $sequence, self::headColumns($record->invoiceId(), $record->fingerprint(), $record->generatedAt()->text));

            return [$record, $row];
        }, 3);
    }

    /** Inserts the chain row unless it exists. */
    private function insertChain(ChainKey $key, IssuerDriver $driver, ?ThrottleKey $throttle): void
    {
        $now = Carbon::now();
        $this->db->table(VerifactuChain::TABLE)->insertOrIgnore([
            'issuer_nif' => $key->issuer->value,
            'installation_number' => $key->installationNumber,
            'environment' => $key->environment->value,
            'driver' => $driver->value,
            'throttle_key' => $throttle?->toString(),
            'sequence' => 0,
            'seeded' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Inserts the throttle row unless it exists. */
    private function ensureThrottle(ThrottleKey $throttle): void
    {
        if ($this->db->table(VerifactuThrottle::TABLE)->where('key', $throttle->toString())->exists()) {
            return;
        }

        $now = Carbon::now();
        $this->db->table(VerifactuThrottle::TABLE)->insertOrIgnore([
            'key' => $throttle->toString(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * On MySQL and PostgreSQL, creates a missing chain row in a short transaction of its own,
     * before the write transaction locks it.
     *
     * Inside the write transaction, an INSERT IGNORE of an existing row takes a shared lock on
     * MySQL, and writers upgrading theirs to the row lock deadlock. Writers that all find the row
     * missing hold gap locks, and their inserts deadlock as well, sometimes on every retry: a run
     * of four writers starting an empty chain at once exhausted the three attempts. Created and
     * committed first, the row exists before any write transaction starts, and those only take
     * the row lock. An empty row left by a write that then fails is taken over by the next one
     * (see openChain()). SQLite needs none of this (see lockChain()).
     *
     * Inside a transaction of the caller, both transactions are savepoints of it: the chain row
     * is committed, and the row lock of the write released, only when the caller's transaction
     * commits, so other writers of the chain wait until then.
     */
    private function prepareChain(ChainKey $key, IssuerDriver $driver, ?ThrottleKey $throttle): void
    {
        if ($this->isSqlite() || $this->chainQuery($key)->exists()) {
            return;
        }

        $this->db->transaction(fn () => $this->insertChain($key, $driver, $throttle), 3);
    }

    /**
     * Reads the chain row under a row lock.
     *
     * SQLite locks the whole database, and only a transaction whose first statement writes waits
     * for the lock instead of failing, so there the row is inserted (or ignored) first. Server
     * databases find the row created by prepareChain(); it is inserted here only if it vanished.
     */
    private function lockChain(ChainKey $key, IssuerDriver $driver, ?ThrottleKey $throttle): object
    {
        $sqlite = $this->isSqlite();

        if ($sqlite) {
            $this->insertChain($key, $driver, $throttle);
        }
        $row = $this->chainQuery($key)->lockForUpdate()->first();
        if ($row === null && ! $sqlite) {
            $this->insertChain($key, $driver, $throttle);
            $row = $this->chainQuery($key)->lockForUpdate()->first();
        }

        return $row ?? throw new UnexpectedValueException(sprintf('The row of chain %s is missing.', $key->toString()));
    }

    private function isSqlite(): bool
    {
        return $this->db instanceof Connection && $this->db->getDriverName() === 'sqlite';
    }

    /**
     * Creates the chain row if needed and reads it under a row lock, refusing a closed chain and a
     * chain of the other driver. An AEAT chain moves to the given throttle when its stored one
     * differs (the throttle scope changed), so its records are sent under the current key.
     *
     * @return array{int, int, ?ChainHead} the row id, the stored sequence and the head
     *
     * @throws ChainConflict when the chain is closed or belongs to the other driver
     */
    private function openChain(ChainKey $key, IssuerDriver $driver, ?ThrottleKey $throttle): array
    {
        $row = $this->lockChain($key, $driver, $throttle);
        $columns = (array) $row;

        $stored = self::text($columns, 'driver', $key);
        if ($stored !== $driver->value && self::isUnused($columns, $key)) {
            // A chain row that never held a record or a seed (its first write failed) belongs
            // to whichever driver writes to it first.
            $this->db->table(VerifactuChain::TABLE)->where('id', self::integer($columns, 'id', $key))->update([
                'driver' => $driver->value,
                'throttle_key' => $throttle?->toString(),
                'updated_at' => Carbon::now(),
            ]);
            $columns = ['driver' => $driver->value, 'throttle_key' => $throttle?->toString()] + $columns;
            $row = (object) $columns;
            $stored = $driver->value;
        }
        if ($stored !== $driver->value) {
            throw new ChainConflict(sprintf('Chain %s is a %s chain; a %s record cannot join it.', $key->toString(), $stored ?? 'unknown', $driver->value));
        }
        if (($columns['closed_at'] ?? null) !== null) {
            throw new ChainConflict(sprintf('Chain %s is closed; nothing more can be appended to it.', $key->toString()));
        }

        [$id, $sequence, $head] = self::readChain($row, $key);

        if ($throttle !== null) {
            $this->ensureThrottle($throttle);
        }
        if ($driver === IssuerDriver::Aeat && $throttle !== null && self::text($columns, 'throttle_key', $key) !== $throttle->toString()) {
            $this->db->table(VerifactuChain::TABLE)->where('id', $id)->update([
                'throttle_key' => $throttle->toString(),
                'updated_at' => Carbon::now(),
            ]);
        }

        return [$id, $sequence, $head];
    }

    /**
     * Whether a chain row has neither records nor a seed.
     *
     * @param  array<array-key, mixed>  $row
     */
    private static function isUnused(array $row, ChainKey $key): bool
    {
        return self::integer($row, 'sequence', $key) === 0 && self::text($row, 'head_hash', $key) === null;
    }

    /**
     * Moves the head from sequence $expected; nothing changes when another writer moved it first.
     *
     * @param  array<string, mixed>  $columns
     *
     * @throws ChainConflict when the head is no longer at $expected
     */
    private function moveHead(ChainKey $key, int $chainId, int $expected, int $sequence, array $columns): void
    {
        $moved = $this->db->table(VerifactuChain::TABLE)
            ->where('id', $chainId)
            ->where('sequence', $expected)
            ->update(['sequence' => $sequence, ...$columns, 'updated_at' => Carbon::now()]);

        if ($moved !== 1) {
            throw new ChainConflict(sprintf('The head of chain %s moved during the write; nothing was stored.', $key->toString()));
        }
    }

    private function chainQuery(ChainKey $key): Builder
    {
        return $this->db->table(VerifactuChain::TABLE)
            ->where('issuer_nif', $key->issuer->value)
            ->where('installation_number', $key->installationNumber)
            ->where('environment', $key->environment->value);
    }

    /** A new record model, or a query for record rows, on this store's connection. */
    private function records(): VerifactuRecord
    {
        $record = new VerifactuRecord;
        $record->setConnection($this->connectionName);

        return $record;
    }

    /**
     * Reads a chain row: its id, its sequence and the head it stores (null for a chain with
     * neither records nor a seed).
     *
     * @return array{int, int, ?ChainHead}
     *
     * @throws UnexpectedValueException when the stored head cannot be read back
     */
    private static function readChain(object $row, ChainKey $key): array
    {
        $row = (array) $row;
        $id = self::integer($row, 'id', $key);
        $sequence = self::integer($row, 'sequence', $key);
        $hash = self::text($row, 'head_hash', $key);
        if ($hash === null) {
            return [$id, $sequence, null];
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr(self::text($row, 'head_issue_date', $key) ?? '', 0, 10));
        if ($date === false) {
            throw new UnexpectedValueException(sprintf('The head of chain %s has an unreadable issue date.', $key->toString()));
        }
        $invoice = new InvoiceId($key->issuer, self::text($row, 'head_number', $key) ?? '', $date);

        // A seeded head (sequence 0) has no timestamp; any other head has the literal text.
        $head = $sequence === 0
            ? ChainHead::restore($invoice, $hash, null, 0, true)
            : ChainHead::restore($invoice, $hash, RecordTimestamp::fromLiteral(self::text($row, 'head_generated_at', $key) ?? '')->value, $sequence, false);

        return [$id, $sequence, $head];
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private static function integer(array $row, string $column, ChainKey $key): int
    {
        $value = $row[$column] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new UnexpectedValueException(sprintf('The column %s of chain %s is not an integer.', $column, $key->toString()));
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private static function text(array $row, string $column, ChainKey $key): ?string
    {
        $value = $row[$column] ?? null;
        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new UnexpectedValueException(sprintf('The column %s of chain %s is not text.', $column, $key->toString()));
    }

    /**
     * @return array<string, mixed>
     */
    private static function headColumns(InvoiceId $id, string $fingerprint, ?string $generatedAt): array
    {
        return [
            'head_number' => $id->number,
            'head_issue_date' => $id->issueDate->format('Y-m-d'),
            'head_hash' => $fingerprint,
            'head_generated_at' => $generatedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function chainColumns(int $chainId, int $sequence, BillingRecord $record): array
    {
        $link = $record->chainLink();

        return [
            'chain_id' => $chainId,
            'sequence' => $sequence,
            'kind' => $record instanceof CancellationRecord ? VerifactuRecordKind::Cancellation : VerifactuRecordKind::Registration,
            'previous_hash' => $link->isFirst() ? null : $link->previousFingerprint(),
            'hash' => $record->fingerprint(),
            'generated_at' => $record->generatedAt()->text,
        ];
    }

    private static function assertIssuer(ChainKey $key, InvoiceId $id): void
    {
        if ($id->issuer->value !== $key->issuer->value) {
            throw new ChainConflict(sprintf('The invoice issuer %s does not own chain %s.', $id->issuer->value, $key->toString()));
        }
    }

    private static function assertLinked(ChainKey $key, BillingRecord $record, ?ChainHead $head): void
    {
        $linked = $head === null ? $record->chainLink()->isFirst() : $head->isLinkedBy($record);

        if (! $linked) {
            throw new ChainConflict(sprintf(
                'The record for invoice %s does not link to the head of chain %s.',
                $record->invoiceId()->number,
                $key->toString(),
            ));
        }
    }

    /**
     * The invoice columns of a record row, always taken from the key and the record.
     *
     * @return array<string, mixed>
     */
    private static function invoiceColumns(ChainKey $key, IssuerDriver $driver, BillingRecord $record): array
    {
        return [
            'issuer_nif' => $key->issuer->value,
            'number' => $record->invoiceId()->number,
            'issue_date' => $record->invoiceId()->issueDate->format('Y-m-d'),
            'environment' => $key->environment,
            'driver' => $driver,
        ];
    }

    /** Checks the stored row against the invoice and the chain's environment. */
    private static function assertRowIsFor(VerifactuRecord $row, InvoiceId $id, ChainKey $key): void
    {
        if ($row->issuer_nif !== $id->issuer->value
            || trim($row->number) !== $id->number
            || substr($row->issue_date, 0, 10) !== $id->issueDate->format('Y-m-d')
            || $row->environment !== $key->environment
            || $row->driver !== IssuerDriver::B2Brouter) {
            throw new InvalidArgumentException(sprintf(
                'The VERI*FACTU record row %d is not the %s B2Brouter row of invoice %s of %s.',
                $row->id,
                $key->environment->value,
                $id->number,
                $id->dateText(),
            ));
        }
    }
}
