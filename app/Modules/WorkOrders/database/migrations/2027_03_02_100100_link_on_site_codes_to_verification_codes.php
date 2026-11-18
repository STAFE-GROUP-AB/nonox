<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The ÄTA on-site code moves onto the signing check service (ADR-046
 * amendment, gap 3, package S3; ADR-074 O4). A new on-site code is kept in
 * signing_verification_codes like every other signer code; this row stays
 * the record of who presented the page on which device, and points at its
 * code. Rows from before keep their own code_hash and are checked as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_order_on_site_codes', function (Blueprint $table) {
            $table->foreignId('verification_code_id')->nullable()->constrained('signing_verification_codes')->nullOnDelete();
        });
    }
};
