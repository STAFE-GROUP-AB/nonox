<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moving registers in from another program (ADR-076):
 *
 * - register_imports: one uploaded file (a register, a list of open invoices
 *   or a ZIP of attachments) with its mapping, validation report and result.
 * - register_import_presets: a column mapping the company saved ("Mitt format").
 * - external_ref on the records an import writes: the number the record had
 *   in the previous program, shown as "Nummer i tidigare program".
 * - import_source on invoices and supplier invoices: IMPORT_OPEN marks an open
 *   item that came in without a voucher, its balance being the SIE opening
 *   balance on 1510 or 2440.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('register_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('kind', 30);
            $table->string('status', 20)->default('MAPPING');
            $table->string('original_name');
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->jsonb('options');
            $table->jsonb('report')->nullable();
            $table->jsonb('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('file_deleted_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'kind', 'sha256']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('register_import_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('name', 80);
            // NONOX field => source header. Header names only, never data.
            $table->jsonb('mapping');
            $table->timestamps();

            $table->unique(['company_id', 'kind', 'name']);
        });

        foreach (['customers', 'suppliers', 'articles', 'invoices', 'supplier_invoices'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('external_ref', 100)->nullable();
                $table->index(['company_id', 'external_ref']);
            });
        }

        foreach (['invoices', 'supplier_invoices'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('import_source', 20)->nullable();
            });
        }
    }
};
