<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The company's own switch for the public Verify page (ADR-046 amendment,
 * gap 6, package S12): teams.signing_verify_enabled, off by default. A company
 * with it off answers on /kontrollera exactly like "not found", and its
 * certificates and summaries carry no QR code. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('signing_verify_enabled')->default(false);
        });
    }
};
