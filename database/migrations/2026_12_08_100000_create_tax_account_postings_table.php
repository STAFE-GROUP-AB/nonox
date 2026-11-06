<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A transaction on the company's tax account at Skatteverket that a person
 * has booked in NONOX from the reconciliation (ADR-054, stage 3). It keeps
 * only Skatteverket's transaction id, the date, the amount and the counter
 * account, so the same transaction is never booked twice: the voucher
 * points back here (vouchers.source_type/source_id). Nothing else from the
 * tax account is stored; the transaction text is in the voucher's
 * description, which is räkenskapsinformation. Personal data: the
 * confirming user's id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_account_postings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedBigInteger('transaction_id');
            $table->date('transaction_date');
            $table->bigInteger('amount_ore');
            $table->unsignedSmallInteger('account_number');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'transaction_id']);
        });
    }
};
