<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Follow-ups to the K2-to-K3 transition tables (S1-P12, after the accounting
 * check and the code review of 2026-11-09):
 * - a building item has the kind BUILDING_COMPONENTS (ADR-059 A.2.6);
 * - k3_transitions keeps the opening balances the transition was computed
 *   on (account number => öre) and whether the right of set-off (K3 29.32)
 *   was confirmed, so a booked transition's sheet never moves with later
 *   changes;
 * - no item can be added to a year whose transition is booked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('k3_transitions', function (Blueprint $table): void {
            $table->jsonb('opening_balances')->nullable();
            $table->boolean('deferred_tax_set_off')->default(false);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE k3_transition_items DROP CONSTRAINT k3_transition_items_values_check;
            ALTER TABLE k3_transition_items ADD CONSTRAINT k3_transition_items_values_check CHECK (
                kind IN ('DEFERRED_TAX', 'PROVISION', 'OTHER')
                AND (rate_bp IS NULL OR rate_bp > 0)
                AND recognised_ore >= 0
                AND (carrying_ore IS NULL OR carrying_ore >= 0)
                AND (tax_value_ore IS NULL OR tax_value_ore >= 0)
                AND (
                    (kind = 'DEFERRED_TAX'
                        AND deferred_tax_kind IN ('BUILDING_COMPONENTS', 'OTHER_ASSET', 'OTHER_LIABILITY', 'LOSS_CARRY_FORWARD')
                        AND account IS NULL AND amount_ore IS NULL
                        AND ((account_from IS NULL) = (account_to IS NULL))
                        AND (account_from IS NULL OR (account_from BETWEEN 1000 AND 2999 AND account_to BETWEEN account_from AND 2999))
                        AND (account_from IS NULL OR account_to < 2080 OR account_from > 2199)
                        AND (account_from IS NULL OR account_to < 2240 OR account_from > 2249)
                        AND (account_from IS NULL OR account_to < 1370 OR account_from > 1379)
                        AND (relief_35_27 = false OR (relief_reason IS NOT NULL AND recognised_ore = 0))
                        AND (relief_35_27 = true OR tax_value_ore IS NOT NULL))
                    OR (kind = 'PROVISION'
                        AND deferred_tax_kind IS NULL AND relief_35_27 = false
                        AND account BETWEEN 2200 AND 2299 AND (account < 2240 OR account > 2249)
                        AND amount_ore < 0)
                    OR (kind = 'OTHER'
                        AND deferred_tax_kind IS NULL AND relief_35_27 = false
                        AND account BETWEEN 1300 AND 2999
                        AND (account < 1370 OR account > 1379)
                        AND (account < 2080 OR account > 2199)
                        AND (account < 2240 OR account > 2249)
                        AND amount_ore <> 0
                        AND k3_reference IS NOT NULL)
                )
            );

            CREATE OR REPLACE FUNCTION k3_transition_items_none_after_booking() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM k3_transitions WHERE fiscal_year_id = NEW.fiscal_year_id) THEN
                    RAISE EXCEPTION 'No K3 transition item can be added after the transition of the year is booked.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER k3_transition_items_none_after_booking
                BEFORE INSERT ON k3_transition_items
                FOR EACH ROW EXECUTE FUNCTION k3_transition_items_none_after_booking();
        SQL);
    }
};
