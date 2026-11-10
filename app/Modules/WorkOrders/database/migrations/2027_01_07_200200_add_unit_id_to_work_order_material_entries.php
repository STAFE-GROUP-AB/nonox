<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Units everywhere (ADR-071 §12.9): a material entry on a work order points
 * at a unit of the company's register. Entries are append-only, so the
 * existing ones keep their unit text; their spellings are added to the
 * register by 2027_01_08_100000_add_unit_id_to_sales_rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_material_entries', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->constrained('units');
        });
    }
};
