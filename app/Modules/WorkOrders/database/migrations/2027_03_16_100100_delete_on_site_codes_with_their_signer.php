<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The record of who presented an ÄTA's signing page on site (ADR-074 O4)
 * pointed at its signer row without ON DELETE, so a signer row with such a
 * record could never be deleted: not when an unsigned link is pruned, and
 * not when a signed envelope's retention has passed (ADR-046 S16). The
 * record is part of the signing evidence and now goes with its signer row.
 * One statement, so the foreign key is never missing: only its delete rule
 * changes; no row, column or index is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE change_order_on_site_codes
                DROP CONSTRAINT change_order_on_site_codes_signing_request_id_foreign,
                ADD CONSTRAINT change_order_on_site_codes_signing_request_id_foreign
                    FOREIGN KEY (signing_request_id) REFERENCES crm_signing_requests (id) ON DELETE CASCADE
            SQL);
    }
};
