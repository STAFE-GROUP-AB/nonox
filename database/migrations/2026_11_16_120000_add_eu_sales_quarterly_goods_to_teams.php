<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Skatteverket's decision that the periodisk sammanställning for goods may
 * be given per calendar quarter instead of per month (skatteförfarandelagen
 * (2011:1244) 35 kap. 4 och 6 §§, #65):
 *
 * - teams.eu_sales_quarterly_goods: the company has such a decision.
 * - teams.eu_sales_quarterly_goods_from: the first day of the quarter the
 *   decision applies from (the quarter after the month it was made).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('eu_sales_quarterly_goods')->default(false);
            $table->date('eu_sales_quarterly_goods_from')->nullable();
        });
    }
};
