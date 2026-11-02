<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verksamhetens art for a payee taxed under A-SINK: Skatteverket asks for
 * field 112 whenever field 275 (A-SINK tax) is filled in, as free text of at
 * most 50 characters, for example "konsert" or "cirkus" (AUD-LON-05, #73).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('activity_type', 50)->nullable()->after('tax_column');
        });
    }
};
