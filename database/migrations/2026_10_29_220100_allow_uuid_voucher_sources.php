<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A voucher's source can be an invoice, whose key is a UUID, so source_id
 * becomes text. Existing values keep their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE vouchers ALTER COLUMN source_id TYPE varchar(64) USING source_id::text');
    }
};
