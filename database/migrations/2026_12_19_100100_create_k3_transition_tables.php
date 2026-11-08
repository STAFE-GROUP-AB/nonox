<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The K2-to-K3 transition (ADR-059 A.2.8, S1-P12; K3 chapter 35, rule sheet
 * S1-R7–R7.7).
 *
 * k3_transition_items: what a person enters for the first K3 year, one row
 * per adjustment of the opening balance sheet:
 * - DEFERRED_TAX: a temporary difference at the transition date (35.27,
 *   29.14), its carrying amount read from accounts at the transition date or
 *   entered, its tax value, and for a deferred tax asset the amount a person
 *   recognises (H6). With the 35.27 relief no deferred tax is recognised and
 *   the reason is required;
 * - PROVISION: a provision K3 chapter 21 requires and K2 did not, on 22xx;
 * - OTHER: any other retroactive restatement, with its K3 reference. The
 *   restatements chapter 35 forbids (35.15, 35.16, 35.18, 35.20) are refused:
 *   intangible and tangible fixed asset accounts (1000–1299) cannot be
 *   restated here.
 *
 * k3_transitions: one row per company and first K3 year, written when a
 * person confirms the transition and it is booked (one K3_TRANSITION voucher
 * dated the first day of the year against 2091, H7 (a)); append-only. Its
 * items get its id and are frozen from then on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('k3_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->date('transition_date');
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->integer('rate_bp')->nullable();
            // The change in equity, credit positive (a decrease is negative).
            $table->bigInteger('equity_effect_ore');
            $table->boolean('comparatives_restated')->default(false);
            $table->boolean('chapter_35_repeated')->default(false);
            $table->foreignId('confirmed_by')->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year_id']);
        });

        Schema::create('k3_transition_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->string('kind', 20);
            $table->string('description', 120);
            // DEFERRED_TAX
            $table->string('deferred_tax_kind', 30)->nullable();
            $table->integer('account_from')->nullable();
            $table->integer('account_to')->nullable();
            $table->bigInteger('carrying_ore')->nullable();
            $table->bigInteger('tax_value_ore')->nullable();
            $table->bigInteger('recognised_ore')->default(0);
            $table->text('recognition_reason')->nullable();
            $table->boolean('relief_35_27')->default(false);
            $table->text('relief_reason')->nullable();
            // PROVISION and OTHER: the account and the amount booked on it, debit positive.
            $table->integer('account')->nullable();
            $table->bigInteger('amount_ore')->nullable();
            $table->string('k3_reference', 60)->nullable();
            // Set once, when the transition is booked; the item is frozen from then on.
            $table->foreignId('k3_transition_id')->nullable()->constrained('k3_transitions');
            $table->integer('rate_bp')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'fiscal_year_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE k3_transition_items ADD CONSTRAINT k3_transition_items_values_check CHECK (
                kind IN ('DEFERRED_TAX', 'PROVISION', 'OTHER')
                AND (rate_bp IS NULL OR rate_bp > 0)
                AND recognised_ore >= 0
                AND (carrying_ore IS NULL OR carrying_ore >= 0)
                AND (tax_value_ore IS NULL OR tax_value_ore >= 0)
                AND (
                    (kind = 'DEFERRED_TAX'
                        AND deferred_tax_kind IN ('OTHER_ASSET', 'OTHER_LIABILITY', 'LOSS_CARRY_FORWARD')
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

            CREATE OR REPLACE FUNCTION k3_transition_items_frozen_after_booking() RETURNS trigger AS $$
            BEGIN
                IF OLD.k3_transition_id IS NOT NULL THEN
                    RAISE EXCEPTION 'A K3 transition item cannot be changed or removed after the transition is booked.';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER k3_transition_items_frozen_after_booking
                BEFORE UPDATE OR DELETE ON k3_transition_items
                FOR EACH ROW EXECUTE FUNCTION k3_transition_items_frozen_after_booking();

            CREATE OR REPLACE FUNCTION k3_transitions_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'A booked K3 transition cannot be changed or removed; correct its voucher instead.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER k3_transitions_append_only
                BEFORE UPDATE OR DELETE ON k3_transitions
                FOR EACH ROW EXECUTE FUNCTION k3_transitions_append_only();
        SQL);
    }
};
