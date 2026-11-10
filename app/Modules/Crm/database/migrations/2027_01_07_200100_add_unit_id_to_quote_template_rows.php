<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Units everywhere (ADR-071 §12.9): a quote template row points at a unit
 * of the company's register. A row without a unit still means the
 * article's sales unit. The existing rows are linked by
 * 2027_01_08_100000_add_unit_id_to_sales_rows, with the other row tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_quote_template_rows', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->constrained('units');
        });
    }
};
