<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;

final class VerifactuMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['einvoicing_verifactu_chains', 'einvoicing_verifactu_throttles', 'einvoicing_verifactu_records'];

    private function migration(): Migration
    {
        $migration = require dirname(__DIR__, 2).'/database/migrations/add_verifactu_to_einvoicing_tables.php';
        self::assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    public function test_migration_is_idempotent(): void
    {
        // The package already ran it; a published copy running again must not fail.
        $this->assertTablesPresent(true);
        $this->migration()->up();
        $this->migration()->up();
        $this->assertTablesPresent(true);

        $this->migration()->down();
        $this->assertTablesPresent(false);
        $this->assertTrue(Schema::hasTable('einvoicing_outbound_documents'));
        $this->assertTrue(Schema::hasTable('einvoicing_ksef_cursors'));

        $this->migration()->up();
        $this->assertTablesPresent(true);
        $this->assertTrue(Schema::hasColumns('einvoicing_verifactu_records', [
            'chain_id', 'sequence', 'kind', 'issuer_nif', 'number', 'issue_date', 'environment', 'driver', 'reference',
            'previous_hash', 'hash', 'generated_at', 'xml', 'source', 'qr_url', 'state', 'csv', 'error_code',
            'error_message', 'error_action', 'attempts', 'b2brouter_invoice_id', 'b2brouter_report_id', 'cross_check',
            'submitted_at', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('einvoicing_verifactu_chains', [
            'issuer_nif', 'installation_number', 'environment', 'driver', 'throttle_key', 'sequence', 'head_number',
            'head_issue_date', 'head_hash', 'head_generated_at', 'seeded', 'closed_at', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('einvoicing_verifactu_throttles', [
            'key', 'last_sent_at', 'wait_seconds', 'next_send_at', 'blocked_at', 'blocked_code', 'blocked_message',
            'created_at', 'updated_at',
        ]));
    }

    private function assertTablesPresent(bool $present): void
    {
        foreach (self::TABLES as $table) {
            $this->assertSame($present, Schema::hasTable($table), $table);
        }
    }
}
