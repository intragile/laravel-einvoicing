<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordKind;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordState;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuChain;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuThrottle;
use IntrAgile\EInvoicing\Laravel\Tests\Support\VerifactuFiles;
use IntrAgile\EInvoicing\Laravel\Tests\Support\VerifactuSamples;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Laravel\Verifactu\DatabaseChainStore;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\BillingRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\CancellationDraft;
use IntrAgile\EInvoicing\Spain\Verifactu\Chainer;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainHead;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainKey;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainLink;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainStore;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\ChainConflict;
use IntrAgile\EInvoicing\Spain\Verifactu\Fingerprint;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\Verifactu\RecordTimestamp;
use IntrAgile\EInvoicing\Spain\Verifactu\RegistrationDraft;
use IntrAgile\EInvoicing\Spain\Verifactu\RegistrationRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\VerifactuQr;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;
use IntrAgile\EInvoicing\Syntax\SafeXml;
use IntrAgile\EInvoicing\Syntax\Verifactu\RecordReader;
use IntrAgile\EInvoicing\Syntax\Verifactu\RecordWriter;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleKey;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleScope;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleState;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class DatabaseChainStoreTest extends TestCase
{
    use RefreshDatabase;

    private const HASH_1 = '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60';

    private const HASH_2 = 'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97';

    private const HASH_3 = '177547C0D57AC74748561D054A9CEC14B4C4EA23D1BEFD6F2E69E3A388F90C68';

    private const SEED_HASH = '1E823150439412AB8A572FBFD66D676CA366074F933949010EC70FDDC1D5FC73';

    private const HASH_1064493 = 'D60F9FD2DDDD19313359612C52222CC74E0B7C6E3C0D64AFBC797F2CE54EF812';

    // The core ChainStoreContract cases, re-implemented on the database store.

    public function test_append_moves_the_head(): void
    {
        $store = $this->store();
        $key = $this->key();
        $this->assertNull($store->head($key));

        $first = $store->append($key, fn (?ChainHead $head): BillingRecord => $this->record('12345678/G33', $head?->link() ?? ChainLink::first()));
        $this->assertSame(self::HASH_1, $first->fingerprint());

        $head = $store->head($key);
        $this->assertNotNull($head);
        $this->assertSame(self::HASH_1, $head->fingerprint);
        $this->assertTrue($head->invoiceId->equals($first->invoiceId()));
        $this->assertSame(1, $head->sequence);
        $this->assertFalse($head->seeded);

        $second = $store->append($key, fn (?ChainHead $head): BillingRecord => $this->record('12345679/G34', $head?->link() ?? ChainLink::first()));
        $this->assertSame(self::HASH_1, $second->chainLink()->previousFingerprint());

        $head = $store->head($key);
        $this->assertNotNull($head);
        $this->assertSame($second->fingerprint(), $head->fingerprint);
        $this->assertSame(2, $head->sequence);
    }

    public function test_record_not_linked_to_head_is_refused(): void
    {
        $store = $this->store();
        $key = $this->key();
        $store->append($key, fn (): BillingRecord => $this->record('12345678/G33', ChainLink::first()));
        $before = $store->head($key);

        // A second record that claims to start the chain again forks it.
        $fork = self::capture(fn () => $store->append($key, fn (): BillingRecord => $this->record('12345679/G34', ChainLink::first())));
        $this->assertInstanceOf(ChainConflict::class, $fork);

        // A record linked to the right invoice but a different fingerprint is refused as well.
        $stale = self::capture(fn () => $store->append($key, fn (): BillingRecord => $this->record(
            '12345679/G34',
            ChainLink::after(VerifactuSamples::invoiceId('12345678/G33', '01-01-2024'), str_repeat('A', 64)),
        )));
        $this->assertInstanceOf(ChainConflict::class, $stale);

        // On an empty chain a record linked to a predecessor is refused.
        $other = $this->key(VerifactuEnvironment::Production);
        $orphan = self::capture(fn () => $store->append($other, fn (): BillingRecord => $this->record(
            '12345679/G34',
            ChainLink::after(VerifactuSamples::invoiceId('12345678/G33', '01-01-2024'), self::HASH_1),
        )));
        $this->assertInstanceOf(ChainConflict::class, $orphan);
        $this->assertNull($store->head($other));

        $this->assertEquals($before, $store->head($key));
        $this->assertSame(1, VerifactuRecord::query()->count());
    }

    public function test_failed_build_stores_nothing(): void
    {
        $store = $this->store();
        $key = $this->key();
        $store->append($key, fn (): BillingRecord => $this->record('12345678/G33', ChainLink::first()));
        $before = $store->head($key);

        $thrown = self::capture(fn () => $store->append($key, static function (): BillingRecord {
            throw new RuntimeException('build failed');
        }));

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertSame('build failed', $thrown->getMessage());
        $this->assertEquals($before, $store->head($key));
        $this->assertSame(1, VerifactuRecord::query()->count());

        // The chain still continues from the untouched head.
        $next = $store->append($key, fn (?ChainHead $head): BillingRecord => $this->record('12345679/G34', $head?->link() ?? ChainLink::first()));
        $this->assertSame(self::HASH_1, $next->chainLink()->previousFingerprint());
    }

    public function test_seed_on_started_chain_is_refused(): void
    {
        $store = $this->store();
        $key = $this->key();
        $last = VerifactuSamples::invoiceId('PROBE-ES-0001', '23-11-2026');

        $seeded = $store->seed($key, $last, self::SEED_HASH);
        $this->assertTrue($seeded->seeded);
        $this->assertSame(0, $seeded->sequence);
        $this->assertNull($seeded->generatedAt);
        $this->assertEquals($seeded, $store->head($key));

        $again = self::capture(fn () => $store->seed($key, $last, self::HASH_1));
        $this->assertInstanceOf(ChainConflict::class, $again);
        $this->assertEquals($seeded, $store->head($key));

        $started = $this->key(VerifactuEnvironment::Production);
        $store->append($started, fn (): BillingRecord => $this->record('12345678/G33', ChainLink::first()));
        $before = $store->head($started);
        $this->assertInstanceOf(ChainConflict::class, self::capture(fn () => $store->seed($started, $last, self::SEED_HASH)));
        $this->assertEquals($before, $store->head($started));
    }

    public function test_chains_are_isolated_per_key(): void
    {
        $store = $this->store();
        $test = $this->key(VerifactuEnvironment::Test);
        $production = $this->key(VerifactuEnvironment::Production);
        $this->assertSame('383', $test->installationNumber);

        $first = $store->append($test, fn (?ChainHead $head): BillingRecord => $this->record('12345678/G33', $head?->link() ?? ChainLink::first()));
        $other = $store->append($production, fn (?ChainHead $head): BillingRecord => $this->record('12345678/G33', $head?->link() ?? ChainLink::first()));

        $this->assertTrue($first->chainLink()->isFirst());
        $this->assertTrue($other->chainLink()->isFirst());
        $this->assertSame(1, $store->head($test)?->sequence);
        $this->assertSame(1, $store->head($production)?->sequence);
        $this->assertSame(2, VerifactuChain::query()->count());
    }

    public function test_seeded_head_is_continued(): void
    {
        $store = $this->store();
        $key = new ChainKey(Nif::parse(VerifactuSamples::SANDBOX_ISSUER_NIF), '383', VerifactuEnvironment::Test);
        $last = VerifactuSamples::invoiceId('PROBE-ES-0001', '23-11-2026', VerifactuSamples::SANDBOX_ISSUER_NIF);
        $store->seed($key, $last, self::SEED_HASH);

        $chain = $store->chain($key);
        $this->assertNotNull($chain);
        $this->assertTrue($chain->seeded);
        $this->assertSame(0, $chain->sequence);

        $record = $store->append($key, fn (?ChainHead $head): BillingRecord => RegistrationRecord::create(
            VerifactuSamples::vectorDraft('PROBE-ES-UBL-0002', '23-11-2026', VerifactuSamples::SANDBOX_ISSUER_NIF),
            $head?->link() ?? ChainLink::first(),
            VerifactuSamples::system(),
            RecordTimestamp::of(new DateTimeImmutable('2026-11-23T12:06:56Z'), new DateTimeZone('Europe/Madrid')),
        ));

        $previous = $record->chainLink()->previous();
        $this->assertNotNull($previous);
        $this->assertSame('B64829179', $previous->issuer->value);
        $this->assertSame('PROBE-ES-0001', $previous->number);
        $this->assertSame('23-11-2026', $previous->dateText());
        $this->assertSame(self::SEED_HASH, $record->chainLink()->previousFingerprint());

        $head = $store->head($key);
        $this->assertNotNull($head);
        $this->assertSame(1, $head->sequence);
        $this->assertFalse($head->seeded);
        $this->assertTrue($head->invoiceId->equals($record->invoiceId()));
        $this->assertSame($record->fingerprint(), $head->fingerprint);
        $this->assertNotNull($head->generatedAt);

        // The chain stays marked as continued from a seed.
        $this->assertTrue($store->chain($key)?->seeded);
        $row = VerifactuRecord::query()->sole();
        $this->assertSame(1, $row->sequence);
        $this->assertSame(self::SEED_HASH, $row->previous_hash);
    }

    // Bridge cases.

    public function test_official_chain_through_the_database(): void
    {
        $chainer = new Chainer(VerifactuSamples::system(), static fn (): DateTimeImmutable => new DateTimeImmutable('2024-01-01T18:20:30Z'));
        $store = $this->store();
        $key = $this->key();

        // The first record goes through the core contract, the others through appendRow().
        $first = $chainer->appendTo($store, $key, VerifactuSamples::vectorDraft('12345678/G33', '01-01-2024'));
        $second = $store->appendRow($key, IssuerDriver::Aeat, $this->throttle(), $this->build($chainer, VerifactuSamples::vectorDraft('12345679/G34', '01-01-2024'), '2024-01-01T18:20:35Z'), ['reference' => 'order-34']);
        $third = $store->appendRow($key, IssuerDriver::Aeat, $this->throttle(), $this->build($chainer, CancellationDraft::of(VerifactuSamples::invoiceId('12345679/G34', '01-01-2024')), '2024-01-01T18:20:40Z'));

        $this->assertSame(self::HASH_1, $first->fingerprint());
        $this->assertSame(self::HASH_2, $second->hash);
        $this->assertSame(self::HASH_3, $third->hash);

        $rows = VerifactuRecord::query()->orderBy('sequence')->get();
        $this->assertSame([1, 2, 3], $rows->pluck('sequence')->all());
        $this->assertSame([self::HASH_1, self::HASH_2, self::HASH_3], $rows->pluck('hash')->all());
        $this->assertSame([null, self::HASH_1, self::HASH_2], $rows->pluck('previous_hash')->all());
        $this->assertSame(
            ['2024-01-01T19:20:30+01:00', '2024-01-01T19:20:35+01:00', '2024-01-01T19:20:40+01:00'],
            $rows->pluck('generated_at')->all(),
        );
        $this->assertSame(
            [VerifactuRecordKind::Registration, VerifactuRecordKind::Registration, VerifactuRecordKind::Cancellation],
            $rows->pluck('kind')->all(),
        );
        $this->assertSame([null, 'order-34', null], $rows->pluck('reference')->all());
        foreach ($rows as $row) {
            $this->assertSame(VerifactuRecordState::Generated, $row->state);
            $this->assertSame('89890001K', $row->issuer_nif);
            $this->assertSame(VerifactuEnvironment::Test, $row->environment);
            $this->assertSame(IssuerDriver::Aeat, $row->driver);
            $this->assertSame(0, $row->attempts);
        }
        $this->assertNotNull($rows[0]->qr_url);
        $this->assertNull($rows[2]->qr_url);
        $this->assertTrue($rows[2]->invoiceId()->equals(VerifactuSamples::invoiceId('12345679/G34', '01-01-2024')));

        $chain = $store->chain($key);
        $this->assertNotNull($chain);
        $this->assertSame(3, $chain->sequence);
        $this->assertSame(self::HASH_3, $chain->head_hash);
        $this->assertSame('12345679/G34', $chain->head_number);
        $this->assertSame('2024-01-01', $chain->head_issue_date);
        $this->assertSame('2024-01-01T19:20:40+01:00', $chain->head_generated_at);
        $this->assertSame(IssuerDriver::Aeat, $chain->driver);
        $this->assertSame($this->throttle()->toString(), $chain->throttle_key);
        $this->assertFalse($chain->seeded);
        $this->assertSame($key->toString(), $chain->key()->toString());
        $this->assertSame(3, $chain->records()->count());
    }

    public function test_head_survives_a_new_store_instance(): void
    {
        $key = $this->key();
        $this->store()->append($key, fn (): BillingRecord => $this->record('12345678/G33', ChainLink::first()));
        $head = $this->store()->head($key);

        $fresh = $this->store();
        $this->assertEquals($head, $fresh->head($key));
        $next = $fresh->append($key, fn (?ChainHead $head): BillingRecord => $this->record('12345679/G34', $head?->link() ?? ChainLink::first()));

        $this->assertSame(self::HASH_1, $next->chainLink()->previousFingerprint());
        $this->assertSame(2, $fresh->head($key)?->sequence);
        $this->assertSame($next->fingerprint(), $this->store()->head($key)?->fingerprint);
        $this->assertSame('2024-01-01T19:20:30+01:00', $fresh->head($key)?->generatedAt?->format('Y-m-d\TH:i:sP'));
    }

    public function test_head_timestamp_text_round_trips_across_dst(): void
    {
        // The application runs in UTC; the records are written in Madrid time on the night the
        // clocks go back, so the same wall-clock text appears with two offsets.
        config()->set('app.timezone', 'UTC');
        date_default_timezone_set('UTC');
        $instants = ['2026-10-25T00:30:00Z', '2026-10-25T01:29:59Z', '2026-10-25T01:30:00Z'];
        $chainer = new Chainer(VerifactuSamples::system(), static function () use (&$instants): DateTimeImmutable {
            $next = array_shift($instants);
            self::assertIsString($next);

            return new DateTimeImmutable($next);
        });
        $key = $this->key();

        $first = $chainer->appendTo($this->store(), $key, VerifactuSamples::vectorDraft('DST-1', '25-10-2026'));
        $this->assertSame('2026-10-25T02:30:00+02:00', $first->generatedAt()->text);

        // Read back through a new store: the literal text and the instant are both kept.
        $reloaded = $this->store()->head($key);
        $this->assertNotNull($reloaded);
        $this->assertNotNull($reloaded->generatedAt);
        $this->assertSame('2026-10-25T02:30:00+02:00', $reloaded->generatedAt->format('Y-m-d\TH:i:sP'));
        $this->assertSame((new DateTimeImmutable('2026-10-25T00:30:00Z'))->getTimestamp(), $reloaded->generatedAt->getTimestamp());
        $this->assertSame('2026-10-25T02:30:00+02:00', $this->store()->chain($key)?->head_generated_at);

        // 02:29:59+01:00 is later than the head as an instant, although its text sorts before it.
        $second = $chainer->appendTo($this->store(), $key, VerifactuSamples::vectorDraft('DST-2', '25-10-2026'));
        $this->assertSame('2026-10-25T02:29:59+01:00', $second->generatedAt()->text);

        // 02:30:00+01:00 has the first record's wall-clock text with the other offset.
        $third = $chainer->appendTo($this->store(), $key, VerifactuSamples::vectorDraft('DST-3', '25-10-2026'));
        $this->assertSame('2026-10-25T02:30:00+01:00', $third->generatedAt()->text);

        $this->assertSame(
            ['2026-10-25T02:30:00+02:00', '2026-10-25T02:29:59+01:00', '2026-10-25T02:30:00+01:00'],
            VerifactuRecord::query()->orderBy('sequence')->pluck('generated_at')->all(),
        );
        $this->assertSame('2026-10-25T02:30:00+01:00', $this->store()->chain($key)?->head_generated_at);
        $this->assertSame('2026-10-25T02:30:00+01:00', $this->store()->head($key)?->generatedAt?->format('Y-m-d\TH:i:sP'));

        // A record one second before the reloaded head, as an instant, is refused.
        $early = new Chainer(VerifactuSamples::system(), static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-25T01:29:59Z'));
        $this->assertInstanceOf(ChainConflict::class, self::capture(fn () => $early->appendTo($this->store(), $key, VerifactuSamples::vectorDraft('DST-4', '25-10-2026'))));
        $this->assertSame(3, VerifactuRecord::query()->count());
    }

    public function test_sqlite_writes_before_it_reads_the_chain(): void
    {
        $store = $this->store();
        $key = $this->key();
        $store->append($key, fn (?ChainHead $head): BillingRecord => $this->record('12345678/G33', $head?->link() ?? ChainLink::first()));

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        $store->append($key, fn (?ChainHead $head): BillingRecord => $this->record('12345679/G34', $head?->link() ?? ChainLink::first()));

        // The first statement takes SQLite's write lock, so the head is never read under a shared lock.
        $this->assertStringStartsWith('insert or ignore into "einvoicing_verifactu_chains"', self::chainStatements(DB::connection()->getQueryLog())[0]);
    }

    public function test_server_databases_lock_an_existing_chain_without_inserting(): void
    {
        // A SQLite connection that reports a server driver runs the statements MySQL and PostgreSQL get.
        config()->set('database.connections.server', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::extend('server', static fn (array $config, string $name): SQLiteConnection => new class(new PDO('sqlite::memory:'), ':memory:', '', $config + ['name' => $name]) extends SQLiteConnection
        {
            public function getDriverName(): string
            {
                return 'mysql';
            }
        });
        $this->artisan('migrate', [
            '--database' => 'server',
            '--path' => dirname(__DIR__, 2).'/database/migrations/add_verifactu_to_einvoicing_tables.php',
            '--realpath' => true,
        ])->assertSuccessful();

        $db = DB::connection('server');
        $store = new DatabaseChainStore($db, new RecordWriter, $this->throttle());
        $key = $this->key();

        $db->enableQueryLog();
        $store->append($key, fn (?ChainHead $head): BillingRecord => $this->record('12345678/G33', $head?->link() ?? ChainLink::first()));
        $first = self::chainStatements($db->getQueryLog());
        $this->assertStringStartsWith('select * from "einvoicing_verifactu_chains"', $first[0]);
        $this->assertStringStartsWith('insert or ignore into "einvoicing_verifactu_chains"', $first[1]);
        $this->assertStringStartsWith('select * from "einvoicing_verifactu_chains"', $first[2]);

        // On an existing chain an INSERT IGNORE would take a shared row lock first, and two writers
        // upgrading theirs to the row lock deadlock on MySQL; only the locking read may run.
        $db->flushQueryLog();
        $store->append($key, fn (?ChainHead $head): BillingRecord => $this->record('12345679/G34', $head?->link() ?? ChainLink::first()));
        $second = self::chainStatements($db->getQueryLog());
        $this->assertStringStartsWith('select * from "einvoicing_verifactu_chains"', $second[0]);
        $this->assertSame([], array_values(array_filter($second, static fn (string $sql): bool => str_starts_with($sql, 'insert'))));
        $this->assertSame(2, $store->head($key)?->sequence);
    }

    public function test_cas_refuses_a_head_moved_during_build(): void
    {
        $store = $this->store();
        $key = $this->key();
        $store->append($key, fn (): BillingRecord => $this->record('12345678/G33', ChainLink::first()));
        $chainId = $store->chain($key)?->id;
        $this->assertNotNull($chainId);

        // Another writer moves the head after the lock was read and before the head is updated.
        $thrown = self::capture(fn () => $store->append($key, function (?ChainHead $head) use ($chainId): BillingRecord {
            DB::table('einvoicing_verifactu_chains')->where('id', $chainId)->increment('sequence');

            return $this->record('12345679/G34', $head?->link() ?? ChainLink::first());
        }));

        $this->assertInstanceOf(ChainConflict::class, $thrown);
        $this->assertSame(1, VerifactuRecord::query()->count());
        $this->assertSame(1, $store->chain($key)?->sequence);
        $this->assertSame(self::HASH_1, $store->head($key)?->fingerprint);
    }

    public function test_unique_indexes_refuse_duplicate_sequence_and_hash(): void
    {
        $store = $this->store();
        $key = $this->key();
        $store->append($key, fn (): BillingRecord => $this->record('12345678/G33', ChainLink::first()));
        $row = (array) DB::table('einvoicing_verifactu_records')->first();
        unset($row['id']);

        $sameSequence = array_merge($row, ['hash' => str_repeat('B', 64), 'number' => 'OTHER-1']);
        $this->assertInstanceOf(QueryException::class, self::capture(fn () => DB::table('einvoicing_verifactu_records')->insert($sameSequence)));

        $sameHash = array_merge($row, ['sequence' => 2, 'number' => 'OTHER-2']);
        $this->assertInstanceOf(QueryException::class, self::capture(fn () => DB::table('einvoicing_verifactu_records')->insert($sameHash)));

        $chain = (array) DB::table('einvoicing_verifactu_chains')->first();
        unset($chain['id']);
        $this->assertInstanceOf(QueryException::class, self::capture(fn () => DB::table('einvoicing_verifactu_chains')->insert($chain)));

        $this->assertSame(1, VerifactuRecord::query()->count());
    }

    public function test_written_xml_and_qr_are_stored(): void
    {
        $store = $this->store();
        $key = $this->key();
        $record = $store->append($key, fn (): BillingRecord => $this->record('12345678/G33', ChainLink::first()));
        $this->assertInstanceOf(RegistrationRecord::class, $record);

        $row = VerifactuRecord::query()->sole();
        $this->assertSame((new RecordWriter)->write($record), $row->xml);
        $element = SafeXml::loadPreservingWhitespace((string) $row->xml)->documentElement;
        $this->assertNotNull($element);
        $this->assertSame($row->hash, Fingerprint::recompute($element));
        $this->assertSame(self::HASH_1, $row->hash);
        $this->assertSame(VerifactuQr::url($record, VerifactuEnvironment::Test), $row->qr_url);
        $this->assertSame('12345678/G33', $row->number);
        $this->assertSame('2024-01-01', $row->issue_date);
    }

    public function test_foreign_record_seeds_an_empty_chain_and_must_link_after(): void
    {
        $store = $this->store();
        $firstXml = VerifactuSamples::b2brouterFixture('record-1064490.xml');
        $secondXml = VerifactuSamples::b2brouterFixture('record-1064493.xml');
        $first = (new RecordReader)->read($firstXml);
        $second = (new RecordReader)->read($secondXml);
        $key = new ChainKey(Nif::parse(VerifactuSamples::SANDBOX_ISSUER_NIF), $first->system()->installationNumber, VerifactuEnvironment::Test);
        $this->assertSame('1-999999', $key->installationNumber);

        $pending = $this->pendingRow('PROBE-ES-0001');
        $stored = $store->appendForeign($key, $first, $firstXml, $pending, ['b2brouter_report_id' => '1064490']);

        // The first record is a PrimerRegistro, so the chain starts with it and is not seeded.
        $this->assertSame($pending->id, $stored->id);
        $this->assertSame(1, VerifactuRecord::query()->count());
        $stored->refresh();
        $chain = $store->chain($key);
        $this->assertNotNull($chain);
        $this->assertSame($chain->id, $stored->chain_id);
        $this->assertSame(1, $stored->sequence);
        $this->assertSame(self::SEED_HASH, $stored->hash);
        $this->assertNull($stored->previous_hash);
        $this->assertSame($firstXml, $stored->xml);
        $this->assertSame($first->generatedAt()->text, $stored->generated_at);
        $this->assertSame(VerifactuRecordState::Registered, $stored->state);
        $this->assertSame('1064490', $stored->b2brouter_report_id);
        $this->assertFalse($chain->seeded);
        $this->assertSame(IssuerDriver::B2Brouter, $chain->driver);
        $this->assertNull($chain->throttle_key);
        $this->assertSame(0, VerifactuThrottle::query()->count());

        // The next foreign record links to it.
        $next = $store->appendForeign($key, $second, $secondXml, $this->pendingRow('PROBE-ES-UBL-0002'));
        $next->refresh();
        $this->assertSame(2, $next->sequence);
        $this->assertSame(self::SEED_HASH, $next->previous_hash);
        $this->assertSame(self::HASH_1064493, $next->hash);
        $this->assertSame(self::HASH_1064493, $store->head($key)?->fingerprint);

        // The same record again does not link to the new head; its row stays pending.
        $again = $this->pendingRow('PROBE-ES-UBL-0002');
        $this->assertInstanceOf(ChainConflict::class, self::capture(fn () => $store->appendForeign($key, $second, $secondXml, $again)));
        $again->refresh();
        $this->assertNull($again->chain_id);
        $this->assertNull($again->sequence);
        $this->assertNull($again->hash);
        $this->assertSame(VerifactuRecordState::Pending, $again->state);
        $this->assertSame(2, $store->chain($key)?->sequence);
        $this->assertSame(0, VerifactuThrottle::query()->count());
    }

    public function test_foreign_record_with_a_previous_link_seeds_an_empty_chain(): void
    {
        $store = $this->store();
        $xml = VerifactuSamples::b2brouterFixture('record-1064493.xml');
        $record = (new RecordReader)->read($xml);
        $key = new ChainKey(Nif::parse(VerifactuSamples::SANDBOX_ISSUER_NIF), $record->system()->installationNumber, VerifactuEnvironment::Test);

        $row = $store->appendForeign($key, $record, $xml, $this->pendingRow('PROBE-ES-UBL-0002'), ['state' => VerifactuRecordState::AcceptedWithErrors]);

        $row->refresh();
        $chain = $store->chain($key);
        $this->assertNotNull($chain);
        $this->assertTrue($chain->seeded);
        $this->assertSame(1, $chain->sequence);
        $this->assertSame(1, $row->sequence);
        $this->assertSame(self::SEED_HASH, $row->previous_hash);
        $this->assertSame(VerifactuRecordState::AcceptedWithErrors, $row->state);
        $head = $store->head($key);
        $this->assertNotNull($head);
        $this->assertFalse($head->seeded);
        $this->assertSame(self::HASH_1064493, $head->fingerprint);

        // A row that is already attached cannot be attached again.
        $this->assertInstanceOf(ChainConflict::class, self::capture(fn () => $store->appendForeign($key, $record, $xml, $row)));
    }

    public function test_record_of_another_issuer_is_refused(): void
    {
        $store = $this->store();
        $key = new ChainKey(Nif::parse(VerifactuSamples::SANDBOX_ISSUER_NIF), '383', VerifactuEnvironment::Test);

        $thrown = self::capture(fn () => $store->append($key, fn (): BillingRecord => $this->record('12345678/G33', ChainLink::first())));

        $this->assertInstanceOf(ChainConflict::class, $thrown);
        $this->assertStringContainsString('89890001K', $thrown->getMessage());
        $this->assertSame(0, VerifactuRecord::query()->count());
        $this->assertNull($store->head($key));
    }

    public function test_throttle_row_uses_core_throttle_key_and_round_trips_state(): void
    {
        config()->set('app.timezone', 'UTC');
        date_default_timezone_set('UTC');
        $store = $this->store();
        $system = VerifactuSamples::system();
        $installation = ThrottleKey::for($system, VerifactuEnvironment::Test);
        $issuer = ThrottleKey::for($system, VerifactuEnvironment::Production, ThrottleScope::Issuer, Nif::parse(VerifactuSamples::ISSUER_NIF));

        $store->appendRow($this->key(), IssuerDriver::Aeat, $installation, fn (?ChainHead $head): BillingRecord => $this->record('12345678/G33', $head?->link() ?? ChainLink::first()));
        $store->appendRow($this->key(VerifactuEnvironment::Production), IssuerDriver::Aeat, $issuer, fn (?ChainHead $head): BillingRecord => $this->record('12345678/G33', $head?->link() ?? ChainLink::first()));

        $this->assertSame(['383|test', '89890001K|383|production'], VerifactuThrottle::query()->orderBy('id')->pluck('key')->all());
        $this->assertSame('89890001K|383|production', $store->chain($this->key(VerifactuEnvironment::Production))?->throttle_key);

        $throttle = VerifactuThrottle::query()->where('key', $installation->toString())->sole();
        $fresh = $throttle->state();
        $this->assertNull($fresh->lastSentAt);
        $this->assertSame(60, $fresh->waitSeconds);
        $this->assertNull($fresh->nextSendAt());

        // An instant given in another zone is stored as the same instant.
        $state = new ThrottleState(new DateTimeImmutable('2026-10-25T02:59:30+02:00'), 90);
        $throttle->store($state);

        $read = VerifactuThrottle::query()->whereKey($throttle->id)->sole()->state();
        $this->assertNotNull($read->lastSentAt);
        $this->assertSame($state->lastSentAt?->getTimestamp(), $read->lastSentAt->getTimestamp());
        $this->assertSame(90, $read->waitSeconds);
        $this->assertSame($state->nextSendAt()?->getTimestamp(), $read->nextSendAt()?->getTimestamp());
        $reloaded = VerifactuThrottle::query()->whereKey($throttle->id)->sole();
        $this->assertSame($state->nextSendAt()?->getTimestamp(), $reloaded->next_send_at?->getTimestamp());
        $this->assertSame('2026-10-25 00:59:30', DB::table('einvoicing_verifactu_throttles')->where('id', $throttle->id)->value('last_sent_at'));
    }

    public function test_container_builds_the_database_store_lazily(): void
    {
        // Nothing is resolved while the system block is empty.
        $this->assertTrue($this->app->bound(ChainStore::class));

        config()->set('einvoicing.verifactu.schemas_dir', VerifactuFiles::schemasDir());
        config()->set('einvoicing.verifactu.system', [
            'name' => 'Test Producer S.L.',
            'nif' => 'A39200019',
            'foreign_id' => ['type' => null, 'id' => null, 'country' => null],
            'system_name' => 'TestSIF',
            'system_id' => '77',
            'version' => '1.0.03',
            'installation_number' => '383',
            'verifactu_only' => true,
            'multiple_taxpayers_possible' => false,
            'multiple_taxpayers_indicator' => false,
        ]);

        $store = $this->app->make(ChainStore::class);
        $this->assertInstanceOf(DatabaseChainStore::class, $store);
        $store->append($this->key(), fn (): BillingRecord => $this->record('12345678/G33', ChainLink::first()));
        $this->assertSame(['383|test'], VerifactuThrottle::query()->pluck('key')->all());
    }

    public function test_stale_throttle_key_of_an_aeat_chain_is_updated(): void
    {
        $store = $this->store();
        $key = $this->key();
        $installation = ThrottleKey::for(VerifactuSamples::system(), VerifactuEnvironment::Test);
        $issuer = ThrottleKey::for(VerifactuSamples::system(), VerifactuEnvironment::Test, ThrottleScope::Issuer, Nif::parse(VerifactuSamples::ISSUER_NIF));

        $store->appendRow($key, IssuerDriver::Aeat, $installation, fn (?ChainHead $head): BillingRecord => $this->record('12345678/G33', $head?->link() ?? ChainLink::first()));
        $this->assertSame('383|test', $store->chain($key)?->throttle_key);

        // The throttle scope changed to "issuer": the next append moves the chain to the new key.
        $store->appendRow($key, IssuerDriver::Aeat, $issuer, fn (?ChainHead $head): BillingRecord => $this->record('12345679/G34', $head?->link() ?? ChainLink::first()));

        $this->assertSame('89890001K|383|test', $store->chain($key)?->throttle_key);
        $this->assertSame(['383|test', '89890001K|383|test'], VerifactuThrottle::query()->orderBy('id')->pluck('key')->all());
    }

    public function test_append_to_a_chain_of_the_other_driver_is_refused(): void
    {
        $store = $this->store();
        $xml = VerifactuSamples::b2brouterFixture('record-1064490.xml');
        $foreign = (new RecordReader)->read($xml);
        $b2brouter = new ChainKey(Nif::parse(VerifactuSamples::SANDBOX_ISSUER_NIF), $foreign->system()->installationNumber, VerifactuEnvironment::Test);
        $store->appendForeign($b2brouter, $foreign, $xml, $this->pendingRow('PROBE-ES-0001'));
        $before = $store->head($b2brouter);

        // Records generated here never join a B2Brouter chain.
        $local = self::capture(fn () => $store->appendRow($b2brouter, IssuerDriver::Aeat, $this->throttle(), fn (?ChainHead $head): BillingRecord => RegistrationRecord::create(
            VerifactuSamples::vectorDraft('LOCAL-1', '23-11-2026', VerifactuSamples::SANDBOX_ISSUER_NIF),
            $head?->link() ?? ChainLink::first(),
            VerifactuSamples::system(),
            RecordTimestamp::of(new DateTimeImmutable('2026-11-24T10:00:00Z'), new DateTimeZone('Europe/Madrid')),
        )));
        $this->assertInstanceOf(ChainConflict::class, $local);
        $this->assertEquals($before, $store->head($b2brouter));
        $this->assertNull($store->chain($b2brouter)?->throttle_key);
        $this->assertSame(0, VerifactuThrottle::query()->count());

        // A B2Brouter record never joins a chain generated here.
        $aeat = new ChainKey(Nif::parse(VerifactuSamples::SANDBOX_ISSUER_NIF), '383', VerifactuEnvironment::Test);
        $store->seed($aeat, VerifactuSamples::invoiceId('PROBE-ES-0001', '23-11-2026', VerifactuSamples::SANDBOX_ISSUER_NIF), self::SEED_HASH);
        $seeded = $store->head($aeat);
        $linkedXml = VerifactuSamples::b2brouterFixture('record-1064493.xml');
        $row = $this->pendingRow('PROBE-ES-UBL-0002');
        // The record links to the seeded head, so only the driver refuses it.
        $this->assertInstanceOf(ChainConflict::class, self::capture(fn () => $store->appendForeign($aeat, (new RecordReader)->read($linkedXml), $linkedXml, $row)));
        $this->assertEquals($seeded, $store->head($aeat));
        $this->assertNull($row->refresh()->chain_id);
        $this->assertSame(1, VerifactuRecord::query()->whereNotNull('chain_id')->count());
    }

    public function test_append_to_a_closed_chain_is_refused(): void
    {
        $store = $this->store();
        $key = $this->key();
        $store->append($key, fn (): BillingRecord => $this->record('12345678/G33', ChainLink::first()));
        VerifactuChain::query()->whereKey($store->chain($key)?->id)->update(['closed_at' => now()]);
        $before = $store->head($key);

        $this->assertInstanceOf(ChainConflict::class, self::capture(fn () => $store->append($key, fn (?ChainHead $head): BillingRecord => $this->record('12345679/G34', $head?->link() ?? ChainLink::first()))));
        $this->assertInstanceOf(ChainConflict::class, self::capture(fn () => $store->appendRow($key, IssuerDriver::Aeat, $this->throttle(), fn (?ChainHead $head): BillingRecord => $this->record('12345679/G34', $head?->link() ?? ChainLink::first()))));
        $this->assertEquals($before, $store->head($key));
        $this->assertSame(1, VerifactuRecord::query()->count());

        $xml = VerifactuSamples::b2brouterFixture('record-1064490.xml');
        $foreign = (new RecordReader)->read($xml);
        $b2brouter = new ChainKey(Nif::parse(VerifactuSamples::SANDBOX_ISSUER_NIF), $foreign->system()->installationNumber, VerifactuEnvironment::Test);
        $store->appendForeign($b2brouter, $foreign, $xml, $this->pendingRow('PROBE-ES-0001'));
        VerifactuChain::query()->whereKey($store->chain($b2brouter)?->id)->update(['closed_at' => now()]);
        $second = VerifactuSamples::b2brouterFixture('record-1064493.xml');
        $row = $this->pendingRow('PROBE-ES-UBL-0002');
        $this->assertInstanceOf(ChainConflict::class, self::capture(fn () => $store->appendForeign($b2brouter, (new RecordReader)->read($second), $second, $row)));
        $this->assertNull($row->refresh()->chain_id);
        $this->assertSame(1, $store->chain($b2brouter)?->sequence);
    }

    public function test_foreign_attributes_cannot_replace_the_invoice_columns(): void
    {
        $store = $this->store();
        $xml = VerifactuSamples::b2brouterFixture('record-1064490.xml');
        $record = (new RecordReader)->read($xml);
        $key = new ChainKey(Nif::parse(VerifactuSamples::SANDBOX_ISSUER_NIF), $record->system()->installationNumber, VerifactuEnvironment::Test);

        $row = $store->appendForeign($key, $record, $xml, $this->pendingRow('PROBE-ES-0001'), [
            'issuer_nif' => '89890001K',
            'number' => 'OTHER-1',
            'issue_date' => '2020-01-01',
            'environment' => VerifactuEnvironment::Production,
            'driver' => IssuerDriver::Aeat,
            'b2brouter_report_id' => '1064490',
        ]);

        $row->refresh();
        $this->assertSame('B64829179', $row->issuer_nif);
        $this->assertSame('PROBE-ES-0001', $row->number);
        $this->assertSame('2026-11-23', $row->issue_date);
        $this->assertSame(VerifactuEnvironment::Test, $row->environment);
        $this->assertSame(IssuerDriver::B2Brouter, $row->driver);
        $this->assertSame('1064490', $row->b2brouter_report_id);
    }

    public function test_foreign_row_is_checked_as_stored(): void
    {
        $store = $this->store();
        $xml = VerifactuSamples::b2brouterFixture('record-1064490.xml');
        $record = (new RecordReader)->read($xml);
        $key = new ChainKey(Nif::parse(VerifactuSamples::SANDBOX_ISSUER_NIF), $record->system()->installationNumber, VerifactuEnvironment::Test);

        // The in-memory model says the right invoice, the stored row another one.
        $other = $this->pendingRow('PROBE-ES-0001');
        VerifactuRecord::query()->whereKey($other->id)->update(['number' => 'OTHER-1']);
        $this->assertInstanceOf(InvalidArgumentException::class, self::capture(fn () => $store->appendForeign($key, $record, $xml, $other)));

        // A row of the other environment does not belong to this chain.
        $production = $this->pendingRow('PROBE-ES-0001');
        VerifactuRecord::query()->whereKey($production->id)->update(['environment' => VerifactuEnvironment::Production->value]);
        $this->assertInstanceOf(InvalidArgumentException::class, self::capture(fn () => $store->appendForeign($key, $record, $xml, $production)));

        $this->assertSame(0, VerifactuRecord::query()->whereNotNull('chain_id')->count());
        $this->assertNull($store->head($key));
    }

    private function store(): DatabaseChainStore
    {
        return new DatabaseChainStore(DB::connection(), new RecordWriter, $this->throttle());
    }

    private function throttle(): ThrottleKey
    {
        return ThrottleKey::for(VerifactuSamples::system(), VerifactuEnvironment::Test);
    }

    private function key(VerifactuEnvironment $environment = VerifactuEnvironment::Test): ChainKey
    {
        return ChainKey::for(Nif::parse(VerifactuSamples::ISSUER_NIF), VerifactuSamples::system(), $environment);
    }

    private function record(string $number, ChainLink $link): BillingRecord
    {
        $at = RecordTimestamp::of(new DateTimeImmutable('2024-01-01T18:20:30Z'), new DateTimeZone('Europe/Madrid'));

        return RegistrationRecord::create(VerifactuSamples::vectorDraft($number, '01-01-2024'), $link, VerifactuSamples::system(), $at);
    }

    /**
     * @return Closure(?ChainHead): BillingRecord
     */
    private function build(Chainer $chainer, RegistrationDraft|CancellationDraft $draft, string $now): Closure
    {
        return static fn (?ChainHead $head): BillingRecord => $chainer->append($draft, $head, new DateTimeImmutable($now))[0];
    }

    private function pendingRow(string $number): VerifactuRecord
    {
        return VerifactuRecord::query()->create([
            'kind' => VerifactuRecordKind::Registration,
            'issuer_nif' => VerifactuSamples::SANDBOX_ISSUER_NIF,
            'number' => $number,
            'issue_date' => '2026-11-23',
            'environment' => VerifactuEnvironment::Test,
            'driver' => IssuerDriver::B2Brouter,
            'state' => VerifactuRecordState::Pending,
        ]);
    }

    /**
     * The logged statements that touch the chain table, in order.
     *
     * @param  array<array-key, array{query: string}>  $log
     * @return list<string>
     */
    private static function chainStatements(array $log): array
    {
        return array_values(array_filter(
            array_map(static fn (array $entry): string => $entry['query'], $log),
            static fn (string $sql): bool => str_contains($sql, '"einvoicing_verifactu_chains"'),
        ));
    }

    private static function capture(Closure $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
    }
}
