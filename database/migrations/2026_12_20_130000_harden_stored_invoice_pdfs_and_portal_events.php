<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Review of the customer portal (ADR-075):
 *
 * - invoices.stores_issued_pdf: set when the invoice is issued with stored
 *   PDFs on. Such an invoice's PDF is stored the first time anything needs
 *   it (the e-mail may run before the queued job), so what is sent is what
 *   is stored. Invoices issued before keep being rendered from their data.
 * - invoice_documents: a stored PDF can be deleted only together with its
 *   company (the cascade from teams), never on its own.
 * - customer_portal_events: when a contact removes their login, the IP
 *   address and browser on their sign-in events may be cleared (and the
 *   contact id, by the foreign key); nothing else may change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('stores_issued_pdf')->default(false);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoice_documents_no_delete() RETURNS trigger AS $$
            BEGIN
                -- Only the cascade from a deleted company: its row in teams is already gone.
                IF EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id) THEN
                    RAISE EXCEPTION 'A stored issued invoice PDF is kept with the invoice (ADR-075).';
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER invoice_documents_no_delete BEFORE DELETE ON invoice_documents
                FOR EACH ROW EXECUTE FUNCTION invoice_documents_no_delete();

            CREATE OR REPLACE FUNCTION customer_portal_events_append_only() RETURNS trigger AS $$
            BEGIN
                -- A removed contact's id, and the IP address and browser of a removed contact's events, may be cleared; nothing else changes.
                IF NEW.id = OLD.id AND NEW.company_id = OLD.company_id AND NEW.customer_id = OLD.customer_id
                    AND (NEW.contact_id IS NOT DISTINCT FROM OLD.contact_id OR NEW.contact_id IS NULL)
                    AND NEW.event = OLD.event
                    AND NEW.subject_type IS NOT DISTINCT FROM OLD.subject_type
                    AND NEW.subject_id IS NOT DISTINCT FROM OLD.subject_id
                    AND (NEW.ip IS NOT DISTINCT FROM OLD.ip OR NEW.ip IS NULL)
                    AND (NEW.user_agent IS NOT DISTINCT FROM OLD.user_agent OR NEW.user_agent IS NULL)
                    AND NEW.created_at = OLD.created_at THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'Customer portal events are append-only (ADR-075).';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
