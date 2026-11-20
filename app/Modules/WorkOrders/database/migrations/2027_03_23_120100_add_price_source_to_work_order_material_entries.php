<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Price lists (ADR-082 P1): a material entry records where its price came
 * from, as the sales rows do (2027_03_23_120000). Entries are append-only
 * (work_order_entries_append_only), so the two columns are written when the
 * entry is made and never after; existing entries keep null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_material_entries', function (Blueprint $table): void {
            $table->string('price_source', 20)->nullable();
            $table->jsonb('price_explanation')->nullable();
        });

        DB::statement("ALTER TABLE work_order_material_entries ADD CONSTRAINT work_order_material_entries_price_source_check CHECK (price_source IS NULL OR price_source IN ('MANUAL', 'AGREEMENT', 'CUSTOMER_LIST', 'CAMPAIGN', 'CURRENCY_LIST', 'BASE', 'CONVERTED'))");
    }
};
