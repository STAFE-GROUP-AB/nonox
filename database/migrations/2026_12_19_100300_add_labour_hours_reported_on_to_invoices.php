<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ROT/RUT invoice whose hours were all reported on earlier invoices of the
 * same job says so ("Arbetade timmar: redovisade på faktura N") instead of
 * printing 0 (re-check of ADR-074). The list of those invoices is part of
 * the issued invoice's immutable content.
 *
 * TODO(legal-verify): whether a ROT/RUT invoice without new hours is
 * accepted in the claim (lag (2009:194) 9 § p. 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->jsonb('labour_hours_reported_on')->nullable();
        });
    }
};
