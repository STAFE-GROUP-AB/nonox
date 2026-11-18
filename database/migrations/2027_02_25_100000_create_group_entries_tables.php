<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The group ledger (ADR-060, addendum 2026-11-07, 2.4; ADR-081 E25, S2-1.5).
 *
 * group_entries: accepted eliminations, manual entries and reversals of a
 * shared group, numbered gapless per group, for one consolidation period
 * (period_from–period_to; entry_date is the period's last day). Spans
 * companies like corporate_groups and corporate_group_runs: it is not any
 * company's data and never writes into any company's books. Each entry is
 * hashed and chained to the group's previous entry, like vouchers.
 *
 * group_entry_rows: the rows, each naming the member company whose amount
 * it eliminates and the group account; one-sided like voucher rows.
 *
 * Append-only by their own triggers (not a change to the voucher triggers).
 * An entry carries its number of rows and its total; a deferred constraint
 * trigger checks at commit that the rows exist, are exactly that many and
 * balance, so a row can neither be left out nor added later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('corporate_group_id')->constrained('corporate_groups')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->date('period_from');
            $table->date('period_to');
            $table->date('entry_date');
            $table->string('kind', 20);
            $table->string('type', 30);
            $table->string('origin', 20);
            $table->foreignId('reverses_id')->nullable()->constrained('group_entries')->restrictOnDelete();
            $table->string('suggestion_key', 120)->nullable();
            $table->string('reason', 255)->nullable();
            $table->jsonb('source')->nullable();
            $table->unsignedSmallInteger('rows_count');
            $table->unsignedBigInteger('total_ore');
            $table->foreignId('accepted_by')->constrained('users');
            $table->timestampTz('accepted_at');
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->timestamp('created_at');

            $table->unique(['corporate_group_id', 'number']);
            $table->unique('reverses_id');
            $table->index(['corporate_group_id', 'period_from', 'period_to']);
        });

        DB::statement("ALTER TABLE group_entries ADD CONSTRAINT group_entries_kind CHECK (kind IN ('ELIMINATION', 'ADJUSTMENT', 'REVERSAL'))");
        DB::statement("ALTER TABLE group_entries ADD CONSTRAINT group_entries_type CHECK (type IN ('SALES_PURCHASES', 'RECEIVABLE_PAYABLE', 'LOAN', 'INTEREST', 'DIVIDEND', 'GROUP_CONTRIBUTION', 'SHARES_EQUITY', 'OTHER'))");
        DB::statement("ALTER TABLE group_entries ADD CONSTRAINT group_entries_origin CHECK (origin IN ('SUGGESTION', 'MANUAL', 'REVERSAL'))");
        DB::statement("ALTER TABLE group_entries ADD CONSTRAINT group_entries_reversal CHECK ((kind = 'REVERSAL') = (reverses_id IS NOT NULL) AND (kind = 'REVERSAL') = (origin = 'REVERSAL'))");
        DB::statement("ALTER TABLE group_entries ADD CONSTRAINT group_entries_suggestion CHECK ((origin = 'SUGGESTION') = (suggestion_key IS NOT NULL))");
        DB::statement('ALTER TABLE group_entries ADD CONSTRAINT group_entries_period CHECK (period_from <= period_to AND entry_date = period_to)');
        DB::statement('ALTER TABLE group_entries ADD CONSTRAINT group_entries_number CHECK (number >= 1 AND rows_count >= 2 AND total_ore > 0)');

        Schema::create('group_entry_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_entry_id')->constrained('group_entries')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('teams')->restrictOnDelete();
            $table->unsignedSmallInteger('account_number')->nullable();
            $table->unsignedSmallInteger('group_account_number');
            $table->bigInteger('debit_ore')->default(0);
            $table->bigInteger('credit_ore')->default(0);

            $table->index('group_entry_id');
            $table->index('company_id');
        });

        DB::statement('ALTER TABLE group_entry_rows ADD CONSTRAINT group_entry_rows_one_sided CHECK (debit_ore >= 0 AND credit_ore >= 0 AND NOT (debit_ore > 0 AND credit_ore > 0) AND (debit_ore > 0 OR credit_ore > 0))');
        DB::statement('ALTER TABLE group_entry_rows ADD CONSTRAINT group_entry_rows_accounts CHECK (group_account_number BETWEEN 1000 AND 8999 AND (account_number IS NULL OR account_number BETWEEN 1000 AND 8999))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION group_ledger_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Group ledger entries cannot be changed or deleted.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER group_entries_append_only
                BEFORE UPDATE OR DELETE ON group_entries
                FOR EACH ROW EXECUTE FUNCTION group_ledger_append_only();

            CREATE TRIGGER group_entry_rows_append_only
                BEFORE UPDATE OR DELETE ON group_entry_rows
                FOR EACH ROW EXECUTE FUNCTION group_ledger_append_only();

            -- A reversal reverses an entry of the same group and period that is not itself a reversal.
            CREATE OR REPLACE FUNCTION group_entries_reversal_target() RETURNS trigger AS $$
            DECLARE
                target group_entries%ROWTYPE;
            BEGIN
                IF NEW.reverses_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT * INTO target FROM group_entries WHERE id = NEW.reverses_id;

                IF target.corporate_group_id <> NEW.corporate_group_id
                    OR target.kind = 'REVERSAL'
                    OR target.period_from <> NEW.period_from
                    OR target.period_to <> NEW.period_to THEN
                    RAISE EXCEPTION 'A group entry can only reverse an entry of the same group and period that is not a reversal.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER group_entries_reversal_target
                BEFORE INSERT ON group_entries
                FOR EACH ROW EXECUTE FUNCTION group_entries_reversal_target();

            -- At commit: the entry has exactly rows_count rows, debit equals credit, and both equal total_ore.
            CREATE OR REPLACE FUNCTION group_entry_balanced() RETURNS trigger AS $$
            DECLARE
                entry_id bigint;
                expected_rows integer;
                expected_total bigint;
                actual_rows integer;
                debit bigint;
                credit bigint;
            BEGIN
                -- Through jsonb, because PL/pgSQL resolves NEW.field against the firing table's row type.
                entry_id := CASE WHEN TG_TABLE_NAME = 'group_entries' THEN (to_jsonb(NEW) ->> 'id')::bigint ELSE (to_jsonb(NEW) ->> 'group_entry_id')::bigint END;

                SELECT rows_count, total_ore INTO expected_rows, expected_total FROM group_entries WHERE id = entry_id;
                SELECT COUNT(*), COALESCE(SUM(debit_ore), 0), COALESCE(SUM(credit_ore), 0)
                    INTO actual_rows, debit, credit FROM group_entry_rows WHERE group_entry_id = entry_id;

                IF actual_rows <> expected_rows OR debit <> credit OR debit <> expected_total THEN
                    RAISE EXCEPTION 'Group entry % does not balance or does not have its rows.', entry_id;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER group_entries_balanced
                AFTER INSERT ON group_entries
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION group_entry_balanced();

            CREATE CONSTRAINT TRIGGER group_entry_rows_balanced
                AFTER INSERT ON group_entry_rows
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION group_entry_balanced();
            SQL);
    }
};
