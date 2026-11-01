<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approved hourly time reports from an earlier month that no run has paid
 * are paid in a later run only when the person running payroll says so
 * (GitHub issue #15). The run keeps the answer per report: the ones to pay
 * now and the ones left for later. The payroll_runs trigger freezes both
 * once the run is attested.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->jsonb('late_time_reports_included')->nullable();
            $table->jsonb('late_time_reports_skipped')->nullable();
        });
    }
};
