<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Incoming payments can also come from NONOX Banking's feed (ADR-034), not
 * only from a camt.054 file. They share bank_transactions and its unique
 * reference per company, so a payment is never booked twice whichever way
 * it arrives. The protecting trigger compares whole rows, so a schema
 * change does not weaken it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bank_transactions ALTER COLUMN bank_file_import_id DROP NOT NULL');
    }
};
