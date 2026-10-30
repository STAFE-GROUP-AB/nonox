<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The fixed asset register (anläggningsregister, AGENTS.md Phase 3): each
 * asset with its depreciation plan, and every booked depreciation month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->integer('number');
            $table->string('name');
            $table->string('category', 30);
            $table->date('acquired_on');
            $table->bigInteger('acquisition_ore');
            $table->bigInteger('residual_ore')->default(0);
            $table->bigInteger('opening_accumulated_ore')->default(0);
            $table->integer('useful_life_months');
            $table->date('depreciation_start');
            $table->integer('asset_account');
            $table->integer('accumulated_account');
            $table->integer('expense_account');
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers');
            $table->string('status', 20)->default('ACTIVE');
            $table->date('disposed_on')->nullable();
            $table->bigInteger('disposal_price_ore')->nullable();
            $table->foreignUuid('disposal_voucher_id')->nullable()->constrained('vouchers');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'number']);
        });

        Schema::create('fixed_asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets');
            $table->char('period', 7);
            $table->bigInteger('amount_ore');
            $table->foreignUuid('voucher_id')->constrained('vouchers');
            $table->timestamps();

            $table->unique(['fixed_asset_id', 'period']);
        });

        // A booked depreciation is part of a posted voucher: it is never changed or removed.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fixed_asset_depreciations_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Booked depreciations cannot be changed or deleted.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fixed_asset_depreciations_append_only
                BEFORE UPDATE OR DELETE ON fixed_asset_depreciations
                FOR EACH ROW EXECUTE FUNCTION fixed_asset_depreciations_append_only();
        SQL);
    }
};
