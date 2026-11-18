<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Manual voucher and credit invoice approvals (ADR-081 E21) name a draft's
 * version as "{draft id}.{fingerprint}" and look up every version of a draft
 * with a prefix match (`subject_id LIKE '{id}.%'`). text_pattern_ops lets
 * that prefix use the index whatever the database collation. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS approval_requests_subject_prefix ON approval_requests (company_id, subject_type, subject_id text_pattern_ops)');
    }
};
