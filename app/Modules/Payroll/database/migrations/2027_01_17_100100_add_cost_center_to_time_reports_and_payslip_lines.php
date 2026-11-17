<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time reports and payslip lines per cost centre (ADR-037, gap 7): Planering
 * writes one time report per date, pay code and cost centre, and the run
 * carries the cost centre to the payslip line it prices. Null means the
 * employment's cost centre, as before. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_reports', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers');
        });

        Schema::table('payslip_lines', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers');
        });
    }
};
