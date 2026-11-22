<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Bulk send for signing (ADR-046 amendment 2026-11-18, gap 8, package S15).
 *
 * signing_bulk_sends: one run of one template with one row per envelope: its
 * status, its counts, who made it and who confirmed it. The uploaded file is
 * never stored (the page reads it once).
 *
 * signing_bulk_send_rows: the rows of a run. `payload` holds the people of a
 * row (the addresses and any access code) encrypted, only until the row is
 * sent or the run ends; it is emptied then, and what stays is the row's
 * number, its status, a short reason and the envelope it became.
 *
 * Additive: two new tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_bulk_sends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->uuid('template_id')->nullable();
            $table->foreign('template_id')->references('id')->on('signing_templates')->nullOnDelete();
            $table->string('status', 12)->default('PREVIEW');
            $table->unsignedSmallInteger('total_rows')->default(0);
            $table->unsignedSmallInteger('valid_rows')->default(0);
            $table->unsignedSmallInteger('invalid_rows')->default(0);
            $table->unsignedSmallInteger('sent_count')->default(0);
            $table->unsignedSmallInteger('failed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE signing_bulk_sends ADD CONSTRAINT signing_bulk_sends_status_check CHECK (status IN ('PREVIEW', 'RUNNING', 'COMPLETED', 'CANCELLED'))");
        DB::statement('ALTER TABLE signing_bulk_sends ADD CONSTRAINT signing_bulk_sends_rows_check CHECK (total_rows <= 500)');

        Schema::create('signing_bulk_send_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->uuid('bulk_send_id');
            $table->foreign('bulk_send_id')->references('id')->on('signing_bulk_sends')->cascadeOnDelete();
            $table->unsignedSmallInteger('row_number');
            $table->string('status', 12);
            $table->string('reason', 255)->nullable();
            $table->text('payload')->nullable();
            $table->uuid('document_id')->nullable();
            $table->uuid('envelope_id')->nullable();
            $table->timestampTz('processed_at')->nullable();

            $table->unique(['bulk_send_id', 'row_number']);
            $table->index(['bulk_send_id', 'status']);
            $table->index('company_id');
        });

        DB::statement("ALTER TABLE signing_bulk_send_rows ADD CONSTRAINT signing_bulk_send_rows_status_check CHECK (status IN ('INVALID', 'PENDING', 'PROCESSING', 'SENT', 'FAILED', 'SKIPPED'))");
    }
};
