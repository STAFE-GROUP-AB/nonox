<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trigram indexes for finding employees in the spotlight (ADR-026). The
 * name expression is the one EmployeesSearchProvider searches. The personal
 * number is encrypted and never indexed.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gin');
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS employees_search_name ON employees USING gin (company_id, (coalesce(first_name, '') || ' ' || coalesce(last_name, '')) gin_trgm_ops)");
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS employees_search_email ON employees USING gin (company_id, email gin_trgm_ops)');
    }
};
