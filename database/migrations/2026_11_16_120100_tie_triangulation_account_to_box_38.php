<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Box 38 of the VAT return (mellanmans försäljning vid trepartshandel) no
 * longer reads account 3107 by its number: BAS 2026 has no account for it
 * (#97). NONOX books triangulation on 3107, so every existing 3107 without a
 * box of its own is tied to box 38, which keeps the returns as they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounts')->where('number', 3107)->whereNull('vat_box')->update(['vat_box' => 38]);
    }
};
