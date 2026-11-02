<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A confirmed customer loss (#57, AUD-FAKT-12): the open part of an invoice
 * is written off on a date, with the output VAT reduced (ML 2023:200 8 kap.
 * 16 §, 7 kap. 43-44 §§), and the invoice is closed as WRITTEN_OFF so the
 * customer ledger keeps agreeing with 1510. Money that comes in afterwards
 * is a recovery (the VAT is reported again, 7 kap. 43 §), kept apart from
 * ordinary payments so it never settles the receivable a second time.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_status_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('DRAFT', 'ISSUED', 'PAID', 'CREDITED', 'WRITTEN_OFF'))");

        Schema::table('invoices', function (Blueprint $table) {
            $table->bigInteger('written_off_ore')->default(0);
            $table->date('written_off_on')->nullable();
            $table->foreignUuid('write_off_voucher_id')->nullable()->constrained('vouchers');
            $table->text('write_off_reason')->nullable();
            $table->bigInteger('recovered_ore')->default(0);
        });

        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->boolean('recovery')->default(false);
        });

        DB::unprepared(<<<'SQL'
            -- An issued invoice keeps its contents: only status, payments and
            -- credits (in kronor and in its own currency), Skatteverket's
            -- ROT/RUT payment and its repayment, a write-off and what was
            -- recovered after it, and sending may change.
            CREATE OR REPLACE FUNCTION invoices_protect_issued() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'issued invoice % cannot be deleted', OLD.id
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status <> 'DRAFT'
                   AND (to_jsonb(NEW) - 'status' - 'paid_ore' - 'credited_ore' - 'foreign_paid_minor' - 'tax_reduction_paid_ore' - 'tax_reduction_repaid_ore' - 'written_off_ore' - 'written_off_on' - 'write_off_voucher_id' - 'write_off_reason' - 'recovered_ore' - 'sent_at' - 'sent_to' - 'updated_at')
                     <> (to_jsonb(OLD) - 'status' - 'paid_ore' - 'credited_ore' - 'foreign_paid_minor' - 'tax_reduction_paid_ore' - 'tax_reduction_repaid_ore' - 'written_off_ore' - 'written_off_on' - 'write_off_voucher_id' - 'write_off_reason' - 'recovered_ore' - 'sent_at' - 'sent_to' - 'updated_at') THEN
                    RAISE EXCEPTION 'issued invoice % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
    }
};
