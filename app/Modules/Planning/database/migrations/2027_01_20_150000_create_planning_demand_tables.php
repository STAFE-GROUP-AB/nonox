<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Bemanningsbehov (ADR-037 amendment 2026-11-17, gap 1): which till gives
 * the shape of the day per area, the company's own hour profile per
 * weekday, a person's override of a day's forecast with the reason, and the
 * staffing targets per weekday and time window. Area null means the whole
 * company. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_sales_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('pos_connection_id')->constrained('pos_connections');
            // Null: the till's sales give the shape of the whole company's day.
            $table->foreignId('area_id')->nullable()->constrained('planning_areas');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'pos_connection_id']);
        });

        Schema::create('planning_hour_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('planning_areas');
            // ISO weekday, 1 = Monday.
            $table->unsignedSmallInteger('weekday');
            // 24 shares in basis points, hour 00 first, summing to 10 000: the company's own estimate.
            $table->jsonb('shares');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'area_id']);
        });

        Schema::create('planning_forecast_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('planning_areas');
            $table->date('date');
            $table->bigInteger('amount_ore');
            $table->string('reason', 255);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'date']);
        });

        Schema::create('planning_staffing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('planning_areas');
            // ISO weekday, or null for every day.
            $table->unsignedSmallInteger('weekday')->nullable();
            // Minutes after midnight, local time: [start, end).
            $table->unsignedSmallInteger('start_minute');
            $table->unsignedSmallInteger('end_minute');
            $table->bigInteger('sales_per_staff_hour_ore')->nullable();
            $table->unsignedSmallInteger('min_staff')->nullable();
            $table->date('valid_from');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'area_id']);
        });

        DB::statement('CREATE UNIQUE INDEX planning_hour_profiles_scope_unique ON planning_hour_profiles (company_id, COALESCE(area_id, 0), weekday)');
        DB::statement('CREATE UNIQUE INDEX planning_forecast_overrides_scope_unique ON planning_forecast_overrides (company_id, COALESCE(area_id, 0), date)');
        DB::statement('ALTER TABLE planning_hour_profiles ADD CONSTRAINT planning_hour_profiles_weekday_check CHECK (weekday BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE planning_forecast_overrides ADD CONSTRAINT planning_forecast_overrides_amount_check CHECK (amount_ore >= 0)');
        DB::statement('ALTER TABLE planning_staffing_rules ADD CONSTRAINT planning_staffing_rules_window_check CHECK ((weekday IS NULL OR weekday BETWEEN 1 AND 7) AND end_minute > start_minute AND end_minute <= 1440)');
        DB::statement('ALTER TABLE planning_staffing_rules ADD CONSTRAINT planning_staffing_rules_target_check CHECK ((sales_per_staff_hour_ore IS NOT NULL AND sales_per_staff_hour_ore > 0) OR (min_staff IS NOT NULL AND min_staff > 0))');
    }
};
