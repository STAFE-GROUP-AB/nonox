<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A confirmed checklist point may carry the reason a person gave and what
 * the confirmation is tied to: for "Skillnaden är avsiktlig" (opening
 * balances that differ from last year's closing balances, ÅRL 2 kap. 4 §
 * andra stycket) a fingerprint of the differing accounts and amounts, so a
 * changed difference voids the confirmation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('closing_checks', function (Blueprint $table) {
            $table->text('reason')->nullable();
            $table->string('fingerprint', 64)->nullable();
        });
    }
};
