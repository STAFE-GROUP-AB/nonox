<?php

declare(strict_types=1);

use App\Domain\Units\UnitBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Units everywhere (ADR-071 §12.9). Invoice, quote, order and recurring
 * invoice rows point at a unit of the company's register; their unit text
 * stays as the printed snapshot. A company marks one unit as the default
 * for new rows (styck from the start). Every unit spelling already used on
 * a row becomes, or matches, a unit of the register. Rows of issued
 * invoices are protected by their trigger and keep only their text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->boolean('row_default')->default(false);
        });

        DB::statement('CREATE UNIQUE INDEX units_one_row_default ON units (company_id) WHERE row_default');

        foreach (['invoice_rows', 'sales_document_rows', 'recurring_invoice_rows'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('unit_id')->nullable()->constrained('units');
            });
        }

        UnitBackfill::rowDefaults();
        UnitBackfill::rows('invoice_rows', updatable: fn ($query) => $query->whereIn('invoice_id', DB::table('invoices')->where('status', 'DRAFT')->select('id')));
        UnitBackfill::rows('sales_document_rows');
        UnitBackfill::rows('recurring_invoice_rows');
    }
};
