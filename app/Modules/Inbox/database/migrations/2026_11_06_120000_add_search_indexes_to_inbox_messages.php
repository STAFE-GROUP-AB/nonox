<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trigram indexes for finding inbox messages by sender and subject in the
 * spotlight (ADR-026). The mail text is encrypted and never indexed.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gin');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS inbox_messages_search_from_name ON inbox_messages USING gin (company_id, from_name gin_trgm_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS inbox_messages_search_from_email ON inbox_messages USING gin (company_id, from_email gin_trgm_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS inbox_messages_search_subject ON inbox_messages USING gin (company_id, subject gin_trgm_ops)');
    }
};
