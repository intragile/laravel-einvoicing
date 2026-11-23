<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The KSeF columns of the outbound table, added by this migration or created with the table. */
    private const OUTBOUND_COLUMNS = ['ksef_number', 'invoicing_mode', 'offline_deadline', 'upo'];

    public function up(): void
    {
        // The package loads this migration itself; a published copy must not fail on it. Loaded
        // from the package, it runs before create_einvoicing_tables, which then creates the
        // outbound table with these columns; on an existing table they are added here.
        if (Schema::hasTable('einvoicing_outbound_documents')) {
            foreach (self::OUTBOUND_COLUMNS as $column) {
                if (Schema::hasColumn('einvoicing_outbound_documents', $column)) {
                    continue;
                }
                Schema::table('einvoicing_outbound_documents', function (Blueprint $table) use ($column): void {
                    match ($column) {
                        'ksef_number' => $table->string('ksef_number', 64)->nullable()->index(),
                        'invoicing_mode' => $table->string('invoicing_mode', 16)->nullable(),
                        'offline_deadline' => $table->timestamp('offline_deadline')->nullable(),
                        default => $table->longText('upo')->nullable(),
                    };
                });
            }
        }

        if (! Schema::hasTable('einvoicing_ksef_cursors')) {
            Schema::create('einvoicing_ksef_cursors', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                // ISO 8601 with microseconds and the UTC offset, exactly as KSeF reported it.
                $table->string('from', 40);
                $table->string('hwm', 40)->nullable();
                $table->json('ids');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoicing_ksef_cursors');

        if (Schema::hasTable('einvoicing_outbound_documents')) {
            $present = array_values(array_filter(
                self::OUTBOUND_COLUMNS,
                static fn (string $column): bool => Schema::hasColumn('einvoicing_outbound_documents', $column),
            ));
            if ($present !== []) {
                // The index is looked up by its column: created with the table it has the explicit
                // name of create_einvoicing_tables, added here the name Laravel generated.
                $index = null;
                foreach (Schema::getIndexes('einvoicing_outbound_documents') as $candidate) {
                    if ($candidate['columns'] === ['ksef_number'] && ! $candidate['primary']) {
                        $index = $candidate['name'];
                    }
                }
                Schema::table('einvoicing_outbound_documents', function (Blueprint $table) use ($present, $index): void {
                    if ($index !== null) {
                        $table->dropIndex($index);
                    }
                    $table->dropColumn($present);
                });
            }
        }
    }
};
