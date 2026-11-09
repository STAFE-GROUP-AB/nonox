<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A signing request's protect trigger guarded updates only, so deleting a
 * SIGNED request took its evidence (and, by cascade, its events) with it. A
 * signed request is now never deleted, and a declined one only after the
 * prune period: PruneSigningLinks and the CRM reminders delete pending,
 * revoked and declined requests twelve months after they ended, never signed
 * ones. The only other way out is the cascade from deleting the company
 * itself (pg_trigger_depth() > 1 and the company row already gone).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION crm_signing_requests_protect_delete() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF pg_trigger_depth() > 1 AND NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id) THEN
                    RETURN OLD;
                END IF;

                IF OLD.status = 'SIGNED' THEN
                    RAISE EXCEPTION 'signed signing request % cannot be deleted', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.status = 'DECLINED' AND (OLD.declined_at IS NULL OR OLD.declined_at >= now() - interval '12 months') THEN
                    RAISE EXCEPTION 'declined signing request % cannot be deleted before the prune period', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN OLD;
            END;
            $$;

            CREATE TRIGGER crm_signing_requests_protect_delete
                BEFORE DELETE ON crm_signing_requests
                FOR EACH ROW EXECUTE FUNCTION crm_signing_requests_protect_delete();
            SQL);
    }
};
