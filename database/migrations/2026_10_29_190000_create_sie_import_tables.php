<?php

declare(strict_types=1);

use App\Domain\Accounting\SieImport\SieImportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SIE import (AGENTS.md §9.2, ADR-008):
 *
 * - sie_imports: one uploaded file, its validation report and the result.
 * - imported_vouchers: which voucher in the file became which voucher here,
 *   with the file's own series, number, registration date and signature.
 * - voucher_row_history: rows the file marks as added (#RTRANS) or removed
 *   (#BTRANS) after the voucher was first registered.
 *
 * The last two are part of the räkenskapsinformation and can only be added to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sie_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->enum('status', array_column(SieImportStatus::cases(), 'value'))->default(SieImportStatus::Analysed->value);
            $table->string('original_name');
            $table->string('file_path');
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->foreignId('fiscal_year_id')->nullable()->constrained('fiscal_years');
            $table->jsonb('report');
            $table->jsonb('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'sha256']);
        });

        Schema::create('imported_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('sie_import_id')->constrained('sie_imports');
            $table->foreignUuid('voucher_id')->unique()->constrained('vouchers');
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->string('original_series', 20);
            $table->string('original_number', 20);
            $table->date('registered_on')->nullable();
            $table->string('signature')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year_id', 'original_series', 'original_number'], 'imported_vouchers_original_unique');
        });

        Schema::create('voucher_row_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('voucher_id')->constrained('vouchers');
            // The row that was added; null for a removed row, which is not part of the voucher.
            $table->foreignId('voucher_row_id')->nullable()->constrained('voucher_rows');
            $table->enum('change', ['ADDED', 'REMOVED']);
            // How many of the voucher's rows come before a removed row, so it can be written back in place.
            $table->unsignedInteger('position');
            $table->unsignedSmallInteger('account_number');
            $table->bigInteger('amount_ore');
            $table->date('transaction_date')->nullable();
            $table->string('description', 255)->nullable();
            $table->decimal('quantity', 15, 4)->nullable();
            $table->string('signature')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'voucher_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '% rows cannot be changed or removed', TG_TABLE_NAME
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER imported_vouchers_append_only
                BEFORE UPDATE OR DELETE ON imported_vouchers
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();

            CREATE TRIGGER imported_vouchers_no_truncate
                BEFORE TRUNCATE ON imported_vouchers
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_no_truncate();

            CREATE TRIGGER voucher_row_history_append_only
                BEFORE UPDATE OR DELETE ON voucher_row_history
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();

            CREATE TRIGGER voucher_row_history_no_truncate
                BEFORE TRUNCATE ON voucher_row_history
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_no_truncate();
            SQL);
    }
};
