<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Signing events are evidence (ADR-046): their trigger refused UPDATE but let
 * a DELETE through. An event may now go only with its signing request, by the
 * foreign key's ON DELETE CASCADE: the delete comes from a referential
 * trigger (pg_trigger_depth() > 1) and the request (or the whole company,
 * whose cascade may reach the events first) is already gone. A direct
 * DELETE of an event is refused. The app's role has no DELETE on the table
 * either (ADR-079 §6); the cascade runs as the table owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION crm_signing_events_delete_with_request() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF pg_trigger_depth() > 1
                   AND (NOT EXISTS (SELECT 1 FROM crm_signing_requests WHERE id = OLD.signing_request_id)
                        OR NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id)) THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'Signing events are removed only with their signing request (ADR-046).'
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER crm_signing_events_delete_with_request
                BEFORE DELETE ON crm_signing_events
                FOR EACH ROW EXECUTE FUNCTION crm_signing_events_delete_with_request();
            SQL);
    }
};
