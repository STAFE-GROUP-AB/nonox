<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trigram indexes for finding people in the staff ledger from the
 * spotlight (ADR-026). The personal number is encrypted and never indexed.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gin');
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS staff_ledger_people_search_name ON staff_ledger_people USING gin (company_id, (coalesce(first_name, '') || ' ' || coalesce(last_name, '')) gin_trgm_ops)");
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS staff_ledger_people_search_email ON staff_ledger_people USING gin (company_id, email gin_trgm_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS staff_ledger_people_search_employer ON staff_ledger_people USING gin (company_id, employer_name gin_trgm_ops)');
    }
};
