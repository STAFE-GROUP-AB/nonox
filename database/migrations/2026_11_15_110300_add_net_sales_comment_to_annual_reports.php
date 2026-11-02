<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The comment on a net sales variation of more than 30 % between the years
 * of the multi-year overview (BFNAR 2016:10 and 2025:2 p. 5.5, #93).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annual_reports', function (Blueprint $table) {
            $table->text('net_sales_comment')->nullable();
        });
    }
};
