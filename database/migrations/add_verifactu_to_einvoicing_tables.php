<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Index names are given explicitly: the generated ones exceed MySQL's 64 characters. Each
    // stays within 54, leaving room for a 10-character table prefix.

    public function up(): void
    {
        // The package loads this migration itself; a published copy must not fail on it.
        if (! Schema::hasTable('einvoicing_verifactu_chains')) {
            Schema::create('einvoicing_verifactu_chains', function (Blueprint $table): void {
                $table->id();
                $table->string('issuer_nif', 9);
                $table->string('installation_number', 100);
                $table->string('environment', 16);
                $table->string('driver', 16);
                // Null for a chain whose records another service submits.
                $table->string('throttle_key', 400)->nullable();
                $table->unsignedBigInteger('sequence')->default(0);
                $table->string('head_number', 60)->nullable();
                $table->date('head_issue_date')->nullable();
                $table->char('head_hash', 64)->nullable();
                // The literal FechaHoraHusoGenRegistro text that entered the hash, never a DB datetime.
                $table->string('head_generated_at', 32)->nullable();
                $table->boolean('seeded')->default(false);
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->unique(['issuer_nif', 'installation_number', 'environment'], 'einv_vf_chains_key_unique');
            });
        }

        if (! Schema::hasTable('einvoicing_verifactu_throttles')) {
            Schema::create('einvoicing_verifactu_throttles', function (Blueprint $table): void {
                $table->id();
                $table->string('key', 400)->unique('einv_vf_throttles_key_unique');
                $table->timestamp('last_sent_at')->nullable();
                $table->unsignedInteger('wait_seconds')->default(60);
                // Derived from last_sent_at and wait_seconds; kept for queries.
                $table->timestamp('next_send_at')->nullable();
                $table->timestamp('blocked_at')->nullable();
                $table->string('blocked_code', 32)->nullable();
                $table->text('blocked_message')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('einvoicing_verifactu_records')) {
            Schema::create('einvoicing_verifactu_records', function (Blueprint $table): void {
                $table->id();
                // Null while a B2Brouter record waits for its registration.
                $table->foreignId('chain_id')->nullable();
                $table->unsignedBigInteger('sequence')->nullable();
                $table->string('kind', 16);
                $table->string('issuer_nif', 9);
                $table->string('number', 60);
                $table->date('issue_date');
                $table->string('environment', 16);
                $table->string('driver', 16);
                $table->string('reference')->nullable()->index('einv_vf_records_reference_index');
                $table->char('previous_hash', 64)->nullable();
                $table->char('hash', 64)->nullable();
                // The literal FechaHoraHusoGenRegistro text that entered the hash, never a DB datetime.
                $table->string('generated_at', 32)->nullable();
                $table->longText('xml')->nullable();
                $table->longText('source')->nullable();
                // The serialized SpanishInvoice extension of a B2Brouter source invoice, which UBL cannot carry.
                $table->longText('source_extension')->nullable();
                $table->text('qr_url')->nullable();
                $table->string('state', 24)->index('einv_vf_records_state_index');
                $table->string('csv', 16)->nullable();
                $table->string('error_code', 32)->nullable();
                $table->text('error_message')->nullable();
                $table->string('error_action', 32)->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->string('b2brouter_invoice_id')->nullable()->index('einv_vf_records_b2b_invoice_index');
                $table->string('b2brouter_report_id')->nullable();
                $table->json('cross_check')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();

                $table->unique(['chain_id', 'sequence'], 'einv_vf_records_sequence_unique');
                $table->unique(['chain_id', 'hash'], 'einv_vf_records_hash_unique');
                $table->index(['issuer_nif', 'number', 'issue_date'], 'einv_vf_records_invoice_index');
                $table->foreign('chain_id', 'einv_vf_records_chain_foreign')->references('id')->on('einvoicing_verifactu_chains');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoicing_verifactu_records');
        Schema::dropIfExists('einvoicing_verifactu_throttles');
        Schema::dropIfExists('einvoicing_verifactu_chains');
    }
};
