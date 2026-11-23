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

final class MigrationIndexNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_base_tables_have_explicit_index_names(): void
    {
        $names = [];
        foreach (['einvoicing_outbound_documents', 'einvoicing_inbound_documents'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                if (! $index['primary']) {
                    $names[] = $index['name'];
                }
            }
        }
        sort($names);

        $this->assertSame([
            'einv_inbound_transport_provider_id_unique',
            'einv_outbound_transport_provider_id_unique',
            'einvoicing_inbound_documents_state_index',
            'einvoicing_outbound_documents_ksef_number_index',
            'einvoicing_outbound_documents_reference_index',
            'einvoicing_outbound_documents_state_index',
        ], $names);
    }

    public function test_base_index_names_fit_mysql_with_a_table_prefix(): void
    {
        $statements = $this->mysqlStatements('create_einvoicing_tables.php');
        $this->assertNotSame([], $statements);

        preg_match_all('/(?:constraint|index|unique)\s+`([^`]+)`/i', implode("\n", $statements), $matches);
        $this->assertCount(6, $matches[1]);

        // MySQL allows 64 characters; 54 leaves room for a 10-character table prefix.
        foreach ($matches[1] as $name) {
            $this->assertLessThanOrEqual(54, strlen($name), $name);
        }
    }

    public function test_ksef_rollback_finds_the_named_index_under_a_table_prefix(): void
    {
        config()->set('database.connections.prefixed', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'app_', 'prefix_indexes' => true, 'foreign_key_constraints' => true]);
        $default = config('database.default');
        config()->set('database.default', 'prefixed');
        try {
            // Loaded from the package, the KSeF migration runs first and the table comes with the columns.
            $this->migration('add_ksef_to_einvoicing_tables.php')->up();
            $this->migration('create_einvoicing_tables.php')->up();
            $this->assertTrue(Schema::hasColumn('einvoicing_outbound_documents', 'ksef_number'));

            $this->migration('add_ksef_to_einvoicing_tables.php')->down();

            $this->assertFalse(Schema::hasColumn('einvoicing_outbound_documents', 'ksef_number'));
            $this->assertFalse(Schema::hasTable('einvoicing_ksef_cursors'));
        } finally {
            config()->set('database.default', $default);
            DB::purge('prefixed');
        }
    }

    private function migration(string $file): Migration
    {
        $migration = require dirname(__DIR__, 2).'/database/migrations/'.$file;
        self::assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    /**
     * The statements a migration sends to MySQL, compiled in pretend mode against a stand-in PDO
     * that only reports a server version, so no server is ever contacted.
     *
     * @return list<string>
     */
    private function mysqlStatements(string $file): array
    {
        $migration = $this->migration($file);

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
            $queries = DB::connection('mysql_pretend')->pretend(static fn () => $migration->up());
        } finally {
            config()->set('database.default', $default);
            DB::purge('mysql_pretend');
        }

        return array_values(array_map(static fn (array $query): string => (string) $query['query'], $queries));
    }
}
