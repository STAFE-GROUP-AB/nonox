<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The temporary differences behind a K3 company's deferred tax (ADR-059,
 * addendum 2026-11-07, A.2.6; K3 29.14–29.19): one row per item and fiscal
 * year, with the carrying amount (read from balance sheet accounts, from the
 * fixed asset register, or entered), the tax value and where it came from,
 * and for a deferred tax asset the amount a person decided to recognise
 * (human decision H6). Untaxed reserves (BAS 2100–2199) can never be an item
 * (K3 29.37). Once the deferred tax of the year is booked, its items are
 * frozen: the rate and the confirming person are set once, and the database
 * refuses any later change or removal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deferred_tax_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->string('kind', 30);
            $table->string('description', 120);
            $table->foreignId('fixed_asset_id')->nullable()->constrained('fixed_assets');
            $table->integer('account_from')->nullable();
            $table->integer('account_to')->nullable();
            // Entered when neither accounts nor the register give the carrying amount.
            $table->bigInteger('carrying_ore')->nullable();
            $table->bigInteger('tax_value_ore');
            $table->string('tax_value_source', 10);
            // For a deferred tax asset: the amount a person decided to recognise (H6), 0 until then.
            $table->bigInteger('recognised_ore')->default(0);
            $table->text('recognition_reason')->nullable();
            // Snapshot at booking.
            $table->integer('rate_bp')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'fiscal_year_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE deferred_tax_items ADD CONSTRAINT deferred_tax_items_values_check CHECK (
                kind IN ('BUILDING_COMPONENTS', 'LOSS_CARRY_FORWARD', 'OTHER_ASSET', 'OTHER_LIABILITY')
                AND tax_value_source IN ('ENTERED', 'INK2', 'REGISTER')
                AND tax_value_ore >= 0
                AND recognised_ore >= 0
                AND (carrying_ore IS NULL OR carrying_ore >= 0)
                AND ((account_from IS NULL) = (account_to IS NULL))
                AND (account_from IS NULL OR (account_from BETWEEN 1000 AND 2999 AND account_to BETWEEN account_from AND 2999))
                AND (account_from IS NULL OR account_to < 2100 OR account_from > 2199)
                AND (rate_bp IS NULL OR rate_bp > 0)
            );

            CREATE OR REPLACE FUNCTION deferred_tax_items_frozen_after_booking() RETURNS trigger AS $$
            BEGIN
                IF OLD.confirmed_by IS NOT NULL THEN
                    RAISE EXCEPTION 'A deferred tax item cannot be changed or removed after the deferred tax is booked.';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER deferred_tax_items_frozen_after_booking
                BEFORE UPDATE OR DELETE ON deferred_tax_items
                FOR EACH ROW EXECUTE FUNCTION deferred_tax_items_frozen_after_booking();
        SQL);
    }
};
