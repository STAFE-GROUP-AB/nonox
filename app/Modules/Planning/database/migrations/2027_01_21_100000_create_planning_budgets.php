<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Budget för personalkostnad (ADR-037 amendment 2026-11-17, gap 2): per
 * company, area (null = the whole company) and ISO week (week_start, a
 * Monday), either a fixed amount or a share of the week's forecast sales in
 * basis points. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('planning_areas');
            $table->date('week_start');
            $table->bigInteger('amount_ore')->nullable();
            // 2 850 = 28,5 % of the week's forecast sales (gap 1).
            $table->unsignedInteger('percent_bp')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'week_start']);
        });

        DB::statement('CREATE UNIQUE INDEX planning_budgets_scope_unique ON planning_budgets (company_id, COALESCE(area_id, 0), week_start)');
        DB::statement('ALTER TABLE planning_budgets ADD CONSTRAINT planning_budgets_kind_check CHECK ((amount_ore IS NULL) <> (percent_bp IS NULL))');
        DB::statement('ALTER TABLE planning_budgets ADD CONSTRAINT planning_budgets_amount_check CHECK (amount_ore IS NULL OR amount_ore >= 0)');
        DB::statement('ALTER TABLE planning_budgets ADD CONSTRAINT planning_budgets_percent_check CHECK (percent_bp IS NULL OR percent_bp BETWEEN 1 AND 10000)');
        DB::statement('ALTER TABLE planning_budgets ADD CONSTRAINT planning_budgets_monday_check CHECK (EXTRACT(ISODOW FROM week_start) = 1)');
    }
};
