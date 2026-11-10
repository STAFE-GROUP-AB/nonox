<?php

declare(strict_types=1);

use App\Domain\Units\UnitBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Units everywhere (ADR-071 §12.9): a material entry on a work order points
 * at a unit of the company's register. Entries are append-only, so the
 * existing ones keep their unit text; their spellings are still added to
 * the register, so the next entry can pick them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_material_entries', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->constrained('units');
        });

        UnitBackfill::rows('work_order_material_entries', update: false);
    }
};
