<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planning data on articles and the demand forecast per article (ADR-081
 * gap 5, E15), the base of the MRP engine. Nothing here is bookkeeping.
 *
 * Quantities are milli-units of the article's stock unit. The lead time is in
 * days; planning_method is NONE, REORDER_POINT or MRP and is NONE for every
 * article that exists, so no current behaviour changes. A forecast row is one
 * article in one period: a week starting on a Monday or a month starting on
 * the first, entered by hand (MANUAL) or computed from history (HISTORY).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->bigInteger('safety_stock_milli')->default(0);
            $table->bigInteger('lot_minimum_milli')->nullable();
            $table->bigInteger('lot_multiple_milli')->nullable();
            $table->string('planning_method', 20)->default('NONE');
        });

        DB::statement("ALTER TABLE articles ADD CONSTRAINT articles_planning_data_check CHECK (planning_method IN ('NONE', 'REORDER_POINT', 'MRP') AND safety_stock_milli >= 0 AND (lot_minimum_milli IS NULL OR lot_minimum_milli > 0) AND (lot_multiple_milli IS NULL OR lot_multiple_milli > 0))");

        Schema::create('demand_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles');
            // The Monday of the week, or the first of the month.
            $table->date('period_start');
            // WEEK or MONTH
            $table->string('period_kind', 5);
            $table->bigInteger('quantity_milli');
            // MANUAL or HISTORY
            $table->string('source', 10);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'article_id', 'period_kind', 'period_start'], 'demand_forecasts_period_unique');
            $table->index(['company_id', 'period_kind', 'period_start']);
        });

        DB::statement("ALTER TABLE demand_forecasts ADD CONSTRAINT demand_forecasts_values_check CHECK (quantity_milli >= 0 AND period_kind IN ('WEEK', 'MONTH') AND source IN ('MANUAL', 'HISTORY') AND (period_kind <> 'WEEK' OR EXTRACT(ISODOW FROM period_start) = 1) AND (period_kind <> 'MONTH' OR EXTRACT(DAY FROM period_start) = 1))");
    }
};
