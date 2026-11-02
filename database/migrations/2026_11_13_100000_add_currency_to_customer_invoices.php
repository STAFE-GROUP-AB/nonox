<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer invoices in a foreign currency (ADR-025, receivables). The
 * existing *_ore columns stay kronor, as booked; the invoice keeps its
 * currency, its amounts as written in hundredths of that currency, and the
 * rate (millionths of a krona per unit, like ExchangeRate), the day and the
 * source of that rate. A payment keeps the foreign amount it paid and the
 * exchange difference it booked (kronor received minus kronor settled:
 * positive a gain on 3960, negative a loss on 7960). A customer has a
 * currency new invoices start in; a reminder keeps what remained in the
 * currency; an incoming bank payment keeps the amount the payer sent when it
 * was in another currency than the account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->char('currency', 3)->default('SEK');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->char('invoice_currency', 3)->default('SEK');
            $table->bigInteger('exchange_rate_micros')->nullable();
            $table->date('exchange_rate_date')->nullable();
            $table->string('exchange_rate_source', 20)->nullable();
            $table->bigInteger('foreign_net_minor')->nullable();
            $table->bigInteger('foreign_vat_minor')->nullable();
            $table->bigInteger('foreign_total_minor')->nullable();
            $table->bigInteger('foreign_paid_minor')->default(0);
        });

        Schema::table('invoice_rows', function (Blueprint $table) {
            $table->bigInteger('foreign_unit_price_minor')->nullable();
            $table->bigInteger('foreign_net_minor')->nullable();
        });

        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->bigInteger('foreign_amount_minor')->nullable();
            $table->bigInteger('exchange_difference_ore')->default(0);
        });

        Schema::table('invoice_reminders', function (Blueprint $table) {
            $table->bigInteger('foreign_remaining_minor')->nullable();
        });

        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->char('foreign_currency', 3)->nullable();
            $table->bigInteger('foreign_amount_minor')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE invoices ADD CONSTRAINT invoices_foreign_has_rate
                CHECK (invoice_currency = 'SEK' OR (foreign_total_minor IS NOT NULL AND exchange_rate_micros > 0));

            -- An issued invoice keeps its contents: only status, payments (in
            -- kronor and in its own currency), Skatteverket's ROT/RUT payment
            -- and sending may change.
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
                   AND (to_jsonb(NEW) - 'status' - 'paid_ore' - 'foreign_paid_minor' - 'tax_reduction_paid_ore' - 'sent_at' - 'sent_to' - 'updated_at')
                     <> (to_jsonb(OLD) - 'status' - 'paid_ore' - 'foreign_paid_minor' - 'tax_reduction_paid_ore' - 'sent_at' - 'sent_to' - 'updated_at') THEN
                    RAISE EXCEPTION 'issued invoice % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
    }
};
