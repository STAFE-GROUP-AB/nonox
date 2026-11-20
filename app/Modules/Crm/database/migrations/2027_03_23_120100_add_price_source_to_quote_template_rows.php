<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Price lists (ADR-082 P1): an article row of a quote template records where
 * its price came from, as the sales rows do (2027_03_23_120000). Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_quote_template_rows', function (Blueprint $table): void {
            $table->string('price_source', 20)->nullable();
            $table->jsonb('price_explanation')->nullable();
        });

        DB::statement("ALTER TABLE crm_quote_template_rows ADD CONSTRAINT crm_quote_template_rows_price_source_check CHECK (price_source IS NULL OR price_source IN ('MANUAL', 'AGREEMENT', 'CUSTOMER_LIST', 'CAMPAIGN', 'CURRENCY_LIST', 'BASE', 'CONVERTED'))");
    }
};
