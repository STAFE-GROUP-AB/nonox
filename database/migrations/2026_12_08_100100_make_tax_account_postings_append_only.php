<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A booked tax account transaction is never changed or removed (ADR-054
 * stage 3). When its voucher has been corrected, the row is marked
 * superseded once, so the transaction can be booked again; only one row per
 * transaction may be in force. The trigger allows nothing else, apart from
 * the confirming user's id becoming null when that user is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_account_postings', function (Blueprint $table): void {
            $table->timestampTz('superseded_at')->nullable();
            $table->dropUnique(['company_id', 'transaction_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX tax_account_postings_in_force_unique
                ON tax_account_postings (company_id, transaction_id)
                WHERE superseded_at IS NULL;

            CREATE OR REPLACE FUNCTION tax_account_postings_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE'
                    AND NEW.id = OLD.id
                    AND NEW.company_id = OLD.company_id
                    AND NEW.transaction_id = OLD.transaction_id
                    AND NEW.transaction_date = OLD.transaction_date
                    AND NEW.amount_ore = OLD.amount_ore
                    AND NEW.account_number = OLD.account_number
                    AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at
                    AND (NEW.confirmed_by IS NOT DISTINCT FROM OLD.confirmed_by OR NEW.confirmed_by IS NULL)
                    AND (NEW.superseded_at IS NOT DISTINCT FROM OLD.superseded_at OR (OLD.superseded_at IS NULL AND NEW.superseded_at IS NOT NULL))
                THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'tax_account_postings is append-only: % is not allowed', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER tax_account_postings_no_update_or_delete
                BEFORE UPDATE OR DELETE ON tax_account_postings
                FOR EACH ROW EXECUTE FUNCTION tax_account_postings_append_only();

            CREATE TRIGGER tax_account_postings_no_truncate
                BEFORE TRUNCATE ON tax_account_postings
                FOR EACH STATEMENT EXECUTE FUNCTION tax_account_postings_append_only();
            SQL);
    }
};
