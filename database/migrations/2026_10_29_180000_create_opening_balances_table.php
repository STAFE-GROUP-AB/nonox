<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ingående balanser: the balance of each balance-sheet account on the first
 * day of a fiscal year, in signed öre (debit positive, credit negative, as in
 * SIE #IB). Kept apart from vouchers (ADR-008). They can change only while
 * their fiscal year is open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts');
            $table->bigInteger('amount_ore');
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'account_id']);
            $table->index(['company_id', 'fiscal_year_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION opening_balances_protect_closed_year() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                year_id bigint := CASE WHEN TG_OP = 'DELETE' THEN OLD.fiscal_year_id ELSE NEW.fiscal_year_id END;
            BEGIN
                IF EXISTS (SELECT 1 FROM fiscal_years WHERE id = year_id AND status = 'CLOSED')
                   OR (TG_OP = 'UPDATE' AND EXISTS (SELECT 1 FROM fiscal_years WHERE id = OLD.fiscal_year_id AND status = 'CLOSED')) THEN
                    RAISE EXCEPTION 'opening balances of closed fiscal year % cannot be changed', year_id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$;

            CREATE TRIGGER opening_balances_protect_closed_year
                BEFORE INSERT OR UPDATE OR DELETE ON opening_balances
                FOR EACH ROW EXECUTE FUNCTION opening_balances_protect_closed_year();

            CREATE TRIGGER opening_balances_no_truncate
                BEFORE TRUNCATE ON opening_balances
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_no_truncate();
            SQL);
    }
};
