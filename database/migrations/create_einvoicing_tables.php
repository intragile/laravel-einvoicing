<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The package loads this migration itself; a published copy must not fail on it.
        if (! Schema::hasTable('einvoicing_outbound_documents')) {
            Schema::create('einvoicing_outbound_documents', function (Blueprint $table): void {
                $table->id();
                $table->string('transport', 64);
                $table->string('reference')->nullable()->index();
                $table->string('sender');
                $table->string('receiver');
                $table->text('document_type');
                $table->string('process');
                $table->char('country_c1', 2)->nullable();
                $table->longText('xml');
                $table->string('state', 16)->index();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->unsignedSmallInteger('status_checks')->default(0);
                $table->string('provider_document_id')->nullable();
                $table->string('peppol_message_id')->nullable();
                $table->string('envelope_id')->nullable();
                $table->string('failure_category', 32)->nullable();
                $table->text('failure_message')->nullable();
                $table->string('provider_code')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->unique(['transport', 'provider_document_id']);
            });
        }

        if (! Schema::hasTable('einvoicing_inbound_documents')) {
            Schema::create('einvoicing_inbound_documents', function (Blueprint $table): void {
                $table->id();
                $table->string('transport', 64);
                $table->string('provider_document_id');
                $table->string('sender')->nullable();
                $table->string('receiver')->nullable();
                $table->text('document_type')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->string('state', 16)->index();
                $table->longText('xml')->nullable();
                $table->string('envelope_id')->nullable();
                $table->unsignedSmallInteger('fetch_attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('fetched_at')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
                $table->timestamps();

                $table->unique(['transport', 'provider_document_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoicing_inbound_documents');
        Schema::dropIfExists('einvoicing_outbound_documents');
    }
};
