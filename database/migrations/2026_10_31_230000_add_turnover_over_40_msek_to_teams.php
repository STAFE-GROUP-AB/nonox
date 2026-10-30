<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the company's taxable turnover exceeds 40 million kronor a year:
 * Skatteverket's declaration days for monthly VAT depend on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('turnover_over_40_msek')->default(false);
        });
    }
};
