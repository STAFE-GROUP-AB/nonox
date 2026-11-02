<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A VAT period can be booked more than once (#63): when something is posted
 * in an already settled period, a supplementary settlement voucher books the
 * difference and a new version of the return keeps the corrected boxes.
 * Earlier versions stay as they were (the table is append-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vat_reports', function (Blueprint $table) {
            $table->unsignedSmallInteger('version')->default(1);
            $table->dropUnique(['company_id', 'period_start']);
            $table->unique(['company_id', 'period_start', 'version']);
        });
    }
};
