<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company that is not registered for VAT (#51, AUD-FAKT-5): why it is not
 * (the small-business exemption in ML 2023:200 18 kap. 4 §, only exempt
 * supplies, or a registration that is pending), so its invoices can carry
 * the exemption wording (17 kap. 24 § p. 13, 28 § p. 6). An invoice with VAT
 * from such a company needs an explicit confirmation, since VAT charged
 * wrongly must be paid to the state (16 kap. 23 §), and keeps the exemption
 * wording it was issued with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('vat_exemption', 32)->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('unregistered_vat_confirmed')->default(false);
            $table->text('vat_exemption_text')->nullable();
        });
    }
};
