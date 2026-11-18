<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A substitute (ADR-081 gap 8) is part of the approval history: it explains
 * why someone decided "på uppdrag av" someone else. The row is never deleted,
 * and the only change allowed is revoking it once (revoked_at and
 * revoked_by set together, from empty).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION approval_delegations_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Approval delegations cannot be deleted.';
                END IF;

                IF OLD.revoked_at IS NOT NULL
                    OR NEW.revoked_at IS NULL
                    OR NEW.revoked_by IS NULL
                    OR (to_jsonb(NEW) - 'revoked_at' - 'revoked_by' - 'updated_at') <> (to_jsonb(OLD) - 'revoked_at' - 'revoked_by' - 'updated_at') THEN
                    RAISE EXCEPTION 'An approval delegation can only be revoked, once.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER approval_delegations_guard
                BEFORE UPDATE OR DELETE ON approval_delegations
                FOR EACH ROW EXECUTE FUNCTION approval_delegations_guard();
        SQL);
    }
};
