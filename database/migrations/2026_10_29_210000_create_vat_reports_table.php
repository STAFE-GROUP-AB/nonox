<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A reported VAT period (momsdeklaration, AGENTS.md §8): the boxes as they
 * were when the VAT was booked to 2650, and the settlement voucher. Part of
 * the räkenskapsinformation, so rows can only be added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->jsonb('boxes');
            $table->foreignUuid('voucher_id')->constrained('vouchers');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'period_start']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER vat_reports_append_only
                BEFORE UPDATE OR DELETE ON vat_reports
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();

            CREATE TRIGGER vat_reports_no_truncate
                BEFORE TRUNCATE ON vat_reports
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_no_truncate();
            SQL);
    }
};
