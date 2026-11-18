<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Room for the channel GROUP_DELIVERY (ADR-081 E27): an invoice another
 * company of the same group delivered through NONOX. Widening a varchar
 * keeps every stored value as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE inbox_messages ALTER COLUMN channel TYPE varchar(20)');
    }
};
