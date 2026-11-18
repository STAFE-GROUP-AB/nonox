<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rolling forecasts (ADR-081 gap 2 scope 5, E5) use the columns E3 already
 * made. Two checks keep them honest: a forecast always has a cut-off and a
 * budget never has one, and the cut-off is the last day of a month (the
 * outcome is read up to and including it). Additive: existing budgets have
 * no cut-off and pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE budget_versions ADD CONSTRAINT budget_versions_forecast_cutoff_check
                CHECK ((kind = 'FORECAST') = (forecast_cutoff IS NOT NULL));

            ALTER TABLE budget_versions ADD CONSTRAINT budget_versions_cutoff_month_end_check
                CHECK (forecast_cutoff IS NULL OR forecast_cutoff = (date_trunc('month', forecast_cutoff) + interval '1 month - 1 day')::date);
        SQL);
    }
};
