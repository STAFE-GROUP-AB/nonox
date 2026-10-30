<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accruals (periodiseringar, AGENTS.md Phase 3): a prepaid cost or income
 * spread over months, or an accrued cost or income booked at a month's end
 * and reversed the next month, with every booked step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->integer('number');
            $table->string('name');
            $table->string('kind', 30);
            $table->bigInteger('total_ore');
            $table->integer('balance_account');
            $table->integer('result_account');
            $table->char('start_month', 7);
            $table->integer('months');
            $table->boolean('move_from_result')->default(false);
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'number']);
        });

        Schema::create('accrual_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('accrual_id')->constrained('accruals');
            $table->char('period', 7);
            $table->string('type', 20);
            $table->bigInteger('amount_ore');
            $table->foreignUuid('voucher_id')->constrained('vouchers');
            $table->timestamps();

            $table->unique(['accrual_id', 'period', 'type']);
        });

        // A booked step is part of a posted voucher: it is never changed or removed.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION accrual_postings_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Booked accruals cannot be changed or deleted.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER accrual_postings_append_only
                BEFORE UPDATE OR DELETE ON accrual_postings
                FOR EACH ROW EXECUTE FUNCTION accrual_postings_append_only();
        SQL);
    }
};
