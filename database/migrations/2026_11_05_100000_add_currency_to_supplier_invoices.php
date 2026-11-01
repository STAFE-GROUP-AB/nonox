<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier invoices in a foreign currency (ADR-025): the currency, the amount
 * as written on the invoice in hundredths of that currency, the rate it was
 * booked at in kronor (millionths, like ExchangeRate) and how much of the
 * foreign amount is paid. A payment keeps the foreign amount it paid and the
 * exchange difference it booked; the kronor share of the invoice it settled is
 * amount_ore minus that difference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->char('invoice_currency', 3)->default('SEK');
            $table->bigInteger('foreign_total_minor')->nullable();
            $table->bigInteger('foreign_paid_minor')->default(0);
            $table->bigInteger('exchange_rate_micros')->nullable();
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->bigInteger('foreign_amount_minor')->nullable();
            // Kronor paid minus kronor settled: positive is a loss (7960), negative a gain (3960).
            $table->bigInteger('exchange_difference_ore')->default(0);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_foreign_has_rate
                CHECK (invoice_currency = 'SEK' OR (foreign_total_minor IS NOT NULL AND exchange_rate_micros > 0));

            -- A registered supplier invoice keeps its contents: only status,
            -- approval and payments (in kronor and in its own currency) may change.
            CREATE OR REPLACE FUNCTION supplier_invoices_protect_registered() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'registered supplier invoice % cannot be deleted', OLD.id
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status <> 'DRAFT'
                   AND (to_jsonb(NEW) - 'status' - 'paid_ore' - 'foreign_paid_minor' - 'approved_at' - 'approved_by' - 'updated_at')
                     <> (to_jsonb(OLD) - 'status' - 'paid_ore' - 'foreign_paid_minor' - 'approved_at' - 'approved_by' - 'updated_at') THEN
                    RAISE EXCEPTION 'registered supplier invoice % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);
    }
};
