<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use IntrAgile\EInvoicing\Laravel\Ksef\DatabaseKsefCursorStore;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\Ksef\Inbound\CursorWindow;
use IntrAgile\EInvoicing\Transport\Ksef\Inbound\KsefCursorStore;
use UnexpectedValueException;

final class DatabaseKsefCursorStoreTest extends TestCase
{
    use RefreshDatabase;

    private const OUTBOUND_COLUMNS = ['ksef_number', 'invoicing_mode', 'offline_deadline', 'upo'];

    private function migration(string $file): Migration
    {
        $migration = require dirname(__DIR__, 2).'/database/migrations/'.$file;
        self::assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    public function test_cursor_store_round_trip_and_overwrite(): void
    {
        $store = $this->app->make(KsefCursorStore::class);
        self::assertInstanceOf(DatabaseKsefCursorStore::class, $store);
        $key = 'test:Nip:5265877635:Subject2';
        self::assertNull($store->window($key));

        $from = new DateTimeImmutable('2026-11-20T08:15:30.123456+01:00');
        $hwm = new DateTimeImmutable('2026-11-21T10:00:00.000001+00:00');
        $ids = ['5265877635-20261120-0100001AF629-AF' => false, '5265877635-20261121-01000022B6F1-1C' => true];
        $store->save($key, new CursorWindow($from, $hwm, $ids));

        $read = $store->window($key);
        self::assertNotNull($read);
        self::assertSame($from->format('Y-m-d\TH:i:s.uP'), $read->from->format('Y-m-d\TH:i:s.uP'));
        self::assertNotNull($read->hwm);
        self::assertSame($hwm->format('Y-m-d\TH:i:s.uP'), $read->hwm->format('Y-m-d\TH:i:s.uP'));
        self::assertSame($ids, $read->ids);

        // Overwriting keeps one row per key; another key is independent.
        $store->save($key, $read->acknowledge('5265877635-20261120-0100001AF629-AF')->next());
        $store->save('other', CursorWindow::start($from));
        $next = $store->window($key);
        self::assertNotNull($next);
        self::assertSame($hwm->format('Y-m-d\TH:i:s.uP'), $next->from->format('Y-m-d\TH:i:s.uP'));
        self::assertNull($next->hwm);
        self::assertSame([], $next->ids);
        self::assertSame(1, DB::table('einvoicing_ksef_cursors')->where('key', $key)->count());
        self::assertSame(2, DB::table('einvoicing_ksef_cursors')->count());
        $other = $store->window('other');
        self::assertNotNull($other);
        self::assertSame([], $other->ids);
    }

    public function test_corrupt_row_is_refused_naming_the_key(): void
    {
        $store = $this->app->make(KsefCursorStore::class);
        $store->save('k', CursorWindow::start(new DateTimeImmutable('2026-11-20T00:00:00+00:00')));
        DB::table('einvoicing_ksef_cursors')->where('key', 'k')->update(['ids' => '{"a":"yes"}']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('"k"');
        $store->window('k');
    }

    public function test_migration_is_idempotent(): void
    {
        self::assertTrue(Schema::hasColumns('einvoicing_outbound_documents', self::OUTBOUND_COLUMNS));
        self::assertTrue(Schema::hasTable('einvoicing_ksef_cursors'));

        // A published copy runs next to the package's own migrations.
        $this->migration('create_einvoicing_tables.php')->up();
        $this->migration('add_ksef_to_einvoicing_tables.php')->up();
        self::assertTrue(Schema::hasColumns('einvoicing_outbound_documents', self::OUTBOUND_COLUMNS));

        // Copies published in the same second run the KSeF migration first; the result is the same.
        $this->migration('add_ksef_to_einvoicing_tables.php')->down();
        $this->migration('create_einvoicing_tables.php')->down();
        self::assertFalse(Schema::hasTable('einvoicing_ksef_cursors'));
        $this->migration('add_ksef_to_einvoicing_tables.php')->up();
        $this->migration('create_einvoicing_tables.php')->up();
        self::assertTrue(Schema::hasColumns('einvoicing_outbound_documents', self::OUTBOUND_COLUMNS));
        self::assertTrue(Schema::hasTable('einvoicing_ksef_cursors'));

        // An installation migrated before KSeF gains the columns.
        Schema::table('einvoicing_outbound_documents', function (Blueprint $table): void {
            $table->dropIndex(['ksef_number']);
            $table->dropColumn(self::OUTBOUND_COLUMNS);
        });
        $this->migration('add_ksef_to_einvoicing_tables.php')->up();
        self::assertTrue(Schema::hasColumns('einvoicing_outbound_documents', self::OUTBOUND_COLUMNS));
    }
}
