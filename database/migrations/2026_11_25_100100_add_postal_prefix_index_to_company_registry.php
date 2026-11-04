<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Postnummer börjar med" in the prospect search (ADR-047): a prefix LIKE
 * uses this index whatever the database's collation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX company_registry_postal_code_prefix_index ON company_registry (postal_code varchar_pattern_ops)');
    }
};
