<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank reconciliation (avstämning, AGENTS.md Phase 3): each performed check
 * of a bank account against the bank's statement, with the ledger rows not
 * yet on the statement. A record of what was checked, so it never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->foreignId('account_id')->constrained('accounts');
            $table->date('statement_date');
            $table->bigInteger('statement_balance_ore');
            $table->bigInteger('ledger_balance_ore');
            $table->bigInteger('outstanding_ore');
            $table->bigInteger('difference_ore');
            $table->jsonb('outstanding_row_ids');
            $table->text('explanation')->nullable();
            $table->foreignId('reconciled_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'account_id', 'statement_date']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bank_reconciliations_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Bank reconciliations cannot be changed or deleted.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER bank_reconciliations_append_only
                BEFORE UPDATE OR DELETE ON bank_reconciliations
                FOR EACH ROW EXECUTE FUNCTION bank_reconciliations_append_only();
        SQL);
    }
};
