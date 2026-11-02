<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The threshold for distance sales to consumers in other EU countries
 * (Mervärdesskattelag (2023:200) 6 kap. 62-65 §§, ADR-010):
 *
 * - teams.oss_elected / oss_elected_from: the company has asked Skatteverket
 *   to tax such sales in the buyer's country (6 kap. 64 §) or is registered
 *   for OSS, from that day; the threshold is then not applied.
 * - invoices.swedish_vat_under_threshold: a sale to a consumer in another EU
 *   country that carries Swedish VAT because the company is under the
 *   threshold. It counts towards the threshold but is booked and reported
 *   like a sale in Sweden, not in the OSS return.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('oss_elected')->default(false);
            $table->date('oss_elected_from')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('swedish_vat_under_threshold')->default(false);
        });
    }
};
