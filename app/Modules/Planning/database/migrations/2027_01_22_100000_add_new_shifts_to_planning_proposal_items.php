<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Schedule proposals, v2 (ADR-037 amendment, gap 3, P8): a proposal may also
 * propose new shifts, made from the gap between the staffing demand and the
 * plan. Such an item has no shift yet, so it holds the shift's own times,
 * break and area; it becomes a draft shift only when a planner accepts it.
 * Additive: new nullable columns, and shift_id is no longer required. Every
 * item still has either a shift or its own times (CHECK). The items of P7's
 * proposals all have a shift and read as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planning_proposal_items', function (Blueprint $table) {
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->unsignedInteger('break_minutes')->nullable();
            $table->foreignId('area_id')->nullable()->constrained('planning_areas')->nullOnDelete();

            $table->index('area_id');
        });

        DB::statement('ALTER TABLE planning_proposal_items ALTER COLUMN shift_id DROP NOT NULL');
        DB::statement('ALTER TABLE planning_proposal_items ADD CONSTRAINT planning_proposal_items_shift_or_times CHECK (shift_id IS NOT NULL OR (starts_at IS NOT NULL AND ends_at IS NOT NULL AND ends_at > starts_at))');
    }
};
