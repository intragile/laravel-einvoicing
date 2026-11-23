<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use PDO;

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

    public function test_index_names_fit_mysql_with_a_table_prefix(): void
    {
        $names = [];
        foreach (self::TABLES as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                if ($index['primary']) {
                    continue;
                }
                $names[] = $index['name'];
            }
        }
        sort($names);

        $this->assertSame([
            'einv_vf_chains_key_unique',
            'einv_vf_records_b2b_invoice_index',
            'einv_vf_records_hash_unique',
            'einv_vf_records_invoice_index',
            'einv_vf_records_reference_index',
            'einv_vf_records_sequence_unique',
            'einv_vf_records_state_index',
            'einv_vf_throttles_key_unique',
        ], $names);

        // MySQL allows 64 characters; 54 leaves room for a 10-character table prefix.
        $mysql = $this->mysqlStatements();
        $this->assertNotSame([], $mysql);
        preg_match_all('/(?:constraint|index|unique)\s+`([^`]+)`/i', implode("\n", $mysql), $matches);
        $this->assertContains('einv_vf_records_chain_foreign', $matches[1]);
        foreach ([...$names, ...$matches[1]] as $name) {
            $this->assertLessThanOrEqual(54, strlen($name), $name);
        }
    }

    /**
     * The statements the migration sends to MySQL, compiled in pretend mode against a stand-in
     * PDO that only reports a server version, so no server is ever contacted.
     *
     * @return list<string>
     */
    private function mysqlStatements(): array
    {
        $pdo = new class extends PDO
        {
            public function __construct() {}

            public function getAttribute(int $attribute): mixed
            {
                return $attribute === PDO::ATTR_SERVER_VERSION ? '8.4.0' : null;
            }
        };
        config()->set('database.connections.mysql_pretend', ['driver' => 'mysql_pretend', 'database' => 'pretend', 'prefix' => '', 'prefix_indexes' => true]);
        $this->app->make('db')->extend('mysql_pretend', static fn (array $config, string $name): MySqlConnection => new MySqlConnection($pdo, 'pretend', '', [...$config, 'name' => $name]));

        $default = config('database.default');
        config()->set('database.default', 'mysql_pretend');
        try {
            $queries = DB::connection('mysql_pretend')->pretend(fn () => $this->migration()->up());
        } finally {
            config()->set('database.default', $default);
            DB::purge('mysql_pretend');
        }

        return array_values(array_map(static fn (array $query): string => (string) $query['query'], $queries));
    }

    private function assertTablesPresent(bool $present): void
    {
        foreach (self::TABLES as $table) {
            $this->assertSame($present, Schema::hasTable($table), $table);
        }
    }
}
