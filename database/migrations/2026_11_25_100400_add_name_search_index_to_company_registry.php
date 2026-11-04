<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Sök på namn eller organisationsnummer" in the prospect search (ADR-047):
 * a GIN trigram index on the name answers `name ILIKE '%term%'` over the
 * whole register (about 1.2 million rows) without a sequential scan. The
 * organisation number is searched as a range on its unique index and needs
 * nothing new.
 *
 * pg_trgm ships with PostgreSQL (contrib), is a trusted extension and is
 * already created by the spotlight search's migration (2026_11_06_110000).
 * Should it still be missing and impossible to create (a managed database
 * without contrib), the index is skipped with a warning: the search then
 * works, only slower (a sequential scan, a few hundred milliseconds).
 *
 * Built CONCURRENTLY, outside a transaction, so the weekly register import
 * is not blocked while it is built (measured locally: about 4 s for 1.17
 * million synthetic names; see ADR-047).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable $exception) {
            Log::warning('pg_trgm could not be created; the company register name search runs without its index.', ['error' => $exception->getMessage()]);
        }

        $available = DB::table('pg_extension')->where('extname', 'pg_trgm')->exists();

        if (! $available) {
            return;
        }

        // A CONCURRENTLY build that was interrupted leaves an INVALID index that IF NOT EXISTS would keep for good.
        $invalid = DB::selectOne("SELECT 1 AS invalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = 'company_registry_name_trgm_index' AND NOT i.indisvalid");

        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS company_registry_name_trgm_index');
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS company_registry_name_trgm_index ON company_registry USING gin (name gin_trgm_ops)');
    }
};
