<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A booked K2-to-K3 transition becomes void when its voucher is fully
 * reversed by correction with nothing re-booked (net zero per account across
 * the original and its corrections). `voided_by_voucher_id` is then set once,
 * the way vouchers.corrected_by_voucher_id is; the append-only trigger allows
 * that column and nothing else. Only one transition per year that is not
 * void may exist, so the unique index becomes partial. No item can be added
 * while a transition of the year stands. (S1-P12 re-check, 2026-11-09.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('k3_transitions', function (Blueprint $table): void {
            $table->foreignUuid('voided_by_voucher_id')->nullable()->constrained('vouchers');
            $table->dropUnique(['company_id', 'fiscal_year_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX k3_transitions_standing_unique ON k3_transitions (company_id, fiscal_year_id)
                WHERE voided_by_voucher_id IS NULL;

            CREATE OR REPLACE FUNCTION k3_transitions_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE'
                    AND OLD.voided_by_voucher_id IS NULL
                    AND NEW.voided_by_voucher_id IS NOT NULL
                    AND NEW.id = OLD.id
                    AND NEW.company_id = OLD.company_id
                    AND NEW.fiscal_year_id = OLD.fiscal_year_id
                    AND NEW.transition_date = OLD.transition_date
                    AND NEW.voucher_id IS NOT DISTINCT FROM OLD.voucher_id
                    AND NEW.rate_bp IS NOT DISTINCT FROM OLD.rate_bp
                    AND NEW.equity_effect_ore = OLD.equity_effect_ore
                    AND NEW.comparatives_restated = OLD.comparatives_restated
                    AND NEW.chapter_35_repeated = OLD.chapter_35_repeated
                    AND NEW.confirmed_by = OLD.confirmed_by
                    AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at
                    AND NEW.opening_balances::text IS NOT DISTINCT FROM OLD.opening_balances::text
                    AND NEW.deferred_tax_set_off = OLD.deferred_tax_set_off THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'A booked K3 transition cannot be changed or removed; correct its voucher instead.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION k3_transition_items_none_after_booking() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM k3_transitions WHERE fiscal_year_id = NEW.fiscal_year_id AND voided_by_voucher_id IS NULL) THEN
                    RAISE EXCEPTION 'No K3 transition item can be added after the transition of the year is booked.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
