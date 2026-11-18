<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The sender's own copy of a delivered intra-group invoice (ADR-081 E27):
 * the exact e-invoice file that left the company, on its private disk with
 * its SHA-256, as for an invoice sent over Peppol. Required on every
 * delivered row; a refusal has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intercompany_deliveries', function (Blueprint $table): void {
            $table->string('xml_path', 500)->nullable();
            $table->char('xml_sha256', 64)->nullable();
        });

        DB::statement("ALTER TABLE intercompany_deliveries ADD CONSTRAINT intercompany_deliveries_copy_check CHECK ((status = 'DELIVERED') = (xml_path IS NOT NULL AND xml_sha256 IS NOT NULL) AND (status = 'DELIVERED' OR (xml_path IS NULL AND xml_sha256 IS NULL)))");
    }
};
