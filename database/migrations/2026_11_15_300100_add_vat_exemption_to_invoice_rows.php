<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a row sold in Sweden carries 0 % VAT, and the text the invoice prints
 * for it (ML 2023:200 17 kap. 24 § p. 9 and 13; #54, AUD-FAKT-6). The text
 * is kept on the row so an issued invoice prints what it was issued with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_rows', function (Blueprint $table) {
            $table->string('vat_exemption', 32)->nullable();
            $table->text('vat_exemption_text')->nullable();
        });
    }
};
