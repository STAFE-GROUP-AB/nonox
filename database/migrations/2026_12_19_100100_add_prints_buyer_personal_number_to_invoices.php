<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-074 O5 (TODO(legal-verify) L6): the ROT/RUT buyer's personnummer is
 * printed only on invoices issued from this release on. The flag is set when
 * the invoice is issued and is part of its immutable content, so an invoice
 * issued earlier is always re-rendered as it looked when it was issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('prints_buyer_personal_number')->default(false);
        });
    }
};
