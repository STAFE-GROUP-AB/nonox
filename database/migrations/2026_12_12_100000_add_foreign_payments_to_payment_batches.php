<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment files for supplier invoices in a foreign currency (ADR-014
 * addendum, ADR-025). A payment in a file is in a currency: for kronor
 * nothing changes; for another currency it keeps the amount as written in
 * the file (hundredths of the currency) and the payment information block it
 * was written in, so a status report for one block does not touch the rest.
 * `amount_ore` stays the kronor NONOX expects the bank to take (the invoice's
 * booking rate), which the bank's debit notification replaces when the
 * payment is booked. A supplier gets the Skatteverket payment code
 * (betalningskod) that payments abroad above the reporting limit need.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payment_batch_items', function (Blueprint $table) {
            $table->char('currency', 3)->default('SEK');
            $table->bigInteger('foreign_amount_minor')->nullable();
            $table->string('payment_info_id', 40)->nullable();
        });

        DB::statement("ALTER TABLE supplier_payment_batch_items ADD CONSTRAINT supplier_payment_batch_items_currency_check CHECK ((currency = 'SEK' AND foreign_amount_minor IS NULL) OR (currency <> 'SEK' AND foreign_amount_minor > 0))");

        Schema::table('suppliers', function (Blueprint $table) {
            // Three digits, from Skatteverket's list of payment codes; read by the bank for payments abroad.
            $table->string('payment_code', 3)->nullable();
        });

        Schema::table('payment_responses', function (Blueprint $table) {
            // What the payer instructed, when the debit was booked in another currency (camt AmtDtls/InstdAmt).
            $table->char('instructed_currency', 3)->nullable();
            $table->bigInteger('instructed_amount_minor')->nullable();
        });
    }
};
