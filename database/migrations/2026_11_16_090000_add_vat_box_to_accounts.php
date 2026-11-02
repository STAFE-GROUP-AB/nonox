<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An account outside the BAS ranges can be tied to a box of the VAT return
 * (#64), for example an account of the company's own for mellanmans inköp
 * vid trepartshandel (box 37), which BAS has no account for. Null means the
 * box follows the account number (VatBoxes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('vat_box')->nullable();
        });
    }
};
