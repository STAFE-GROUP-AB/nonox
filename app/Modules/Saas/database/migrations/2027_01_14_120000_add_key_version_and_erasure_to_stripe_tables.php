<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Stripe in NONOX Connect, review fixes (ADR-080 step 2):
 *
 * - stripe_connections.key_version: counts every key the connection has
 *   held, so a 401 to a run that still used an earlier key never revokes
 *   the key that replaced it.
 * - stripe_customer_links.erased_at: the Stripe customer's details were
 *   erased (the NONOX customer anonymised, the Stripe customer erased on
 *   request, or deleted in Stripe); later syncs keep only its id and do not
 *   read the details back in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stripe_connections', function (Blueprint $table) {
            $table->unsignedInteger('key_version')->default(1);
        });

        Schema::table('stripe_customer_links', function (Blueprint $table) {
            $table->timestamp('erased_at')->nullable();
        });
    }
};
