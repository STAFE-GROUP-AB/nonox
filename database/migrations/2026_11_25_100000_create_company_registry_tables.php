<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The instance's company register (ADR-047): legal persons from SCB's and
 * Bolagsverket's open data, for prospecting in the CRM. Reference data held
 * by the instance operator, not company data: no company_id. Sole traders
 * and other natural persons are never loaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_registry', function (Blueprint $table) {
            $table->id();
            // Ten digits, no hyphen: only legal persons (third digit 2 or more).
            $table->char('org_number', 10)->unique();
            $table->string('name');
            // SCB's two-digit legal form code (Jurform), e.g. 49 övriga aktiebolag.
            $table->string('legal_form', 2)->nullable();
            // SCB's FtgStat: 0 never active, 1 active, 9 no longer active.
            $table->smallInteger('scb_status')->nullable();
            // SNI 2025: the main code, its division (two digits), and all of them (up to five).
            $table->char('sni_code', 5)->nullable();
            $table->char('sni_division', 2)->nullable();
            $table->jsonb('sni_codes')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('co_address')->nullable();
            $table->string('postal_code', 10)->nullable();
            // The postal town as the source writes it (capitals).
            $table->string('city')->nullable();
            // From SCB's API only (a key is needed): seat municipality and county, size classes, contact details.
            $table->char('municipality_code', 4)->nullable();
            $table->char('county_code', 2)->nullable();
            $table->smallInteger('employee_class')->nullable();
            $table->smallInteger('turnover_class')->nullable();
            $table->smallInteger('turnover_year')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            // SCB's status at Bolagsverket (BolStat), API only.
            $table->smallInteger('bolagsverket_status')->nullable();
            $table->date('registered_on')->nullable();
            $table->date('deregistered_on')->nullable();
            $table->string('deregistration_reason', 40)->nullable();
            // Ongoing bankruptcy (KK), liquidation (LI) or reorganisation (FR …) and since when.
            $table->string('proceedings', 20)->nullable();
            $table->date('proceedings_since')->nullable();
            // Opt-outs: postal advertising (both sources), telemarketing/NIX and e-mail (API).
            $table->boolean('advertising_block')->default(false);
            $table->smallInteger('phone_block')->nullable();
            $table->boolean('email_block')->default(false);
            $table->jsonb('sources')->default(DB::raw("'[]'::jsonb"));
            $table->timestampTz('fetched_at')->nullable();
            $table->timestampTz('bolagsverket_fetched_at')->nullable();
            $table->unsignedBigInteger('last_import_id')->nullable();
            // Set when a complete SCB run no longer had the row.
            $table->timestampTz('gone_at')->nullable();
            $table->timestamps();

            $table->index('sni_code');
            $table->index('sni_division');
            $table->index('city');
            $table->index('postal_code');
            $table->index('legal_form');
            $table->index('registered_on');
            $table->index('county_code');
            $table->index('municipality_code');
            $table->index('employee_class');
            $table->index('turnover_class');
        });

        Schema::create('company_registry_imports', function (Blueprint $table) {
            $table->id();
            // scb-bulk, bolagsverket-bulk or scb-api.
            $table->string('source', 30);
            // The file's ETag or the API's version; an unchanged file is not read twice.
            $table->string('version')->nullable();
            // RUNNING, DONE or FAILED.
            $table->string('status', 10);
            $table->unsignedBigInteger('rows_read')->default(0);
            $table->unsignedBigInteger('rows_written')->default(0);
            $table->unsignedBigInteger('rows_skipped')->default(0);
            $table->unsignedBigInteger('changes')->default(0);
            // Where to resume: rows read for a file, the cursor for the API.
            $table->string('position')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'status']);
        });

        Schema::create('company_registry_changes', function (Blueprint $table) {
            $table->id();
            $table->char('org_number', 10);
            $table->string('field', 40);
            $table->string('old_value', 500)->nullable();
            $table->string('new_value', 500)->nullable();
            $table->string('source', 30);
            $table->foreignId('import_id')->nullable()->constrained('company_registry_imports')->nullOnDelete();
            $table->timestampTz('detected_at');

            $table->index(['org_number', 'detected_at']);
            $table->index('detected_at');
        });

        // Names for codes that only the API's code tables give (municipalities, status at Bolagsverket).
        Schema::create('company_registry_codes', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('code', 10);
            $table->string('name');
            $table->timestamps();

            $table->unique(['kind', 'code']);
        });
    }
};
