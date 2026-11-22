<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Bulk send for signing (ADR-046 S15, review): the template as the person saw it in the preview, as a SHA-256 of its
 * roles, fields, defaults and file. A template changed after the preview refuses the confirmation and stops a run
 * that is going. Additive: one nullable column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_bulk_sends', function (Blueprint $table) {
            $table->char('template_fingerprint', 64)->nullable();
        });
    }
};
