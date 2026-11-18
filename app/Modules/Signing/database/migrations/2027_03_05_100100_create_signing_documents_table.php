<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Standalone documents for signing (ADR-046 amendment 2026-11-18, gap 10,
 * package S7): any PDF a company uploads, kept as its own file. The file
 * never changes: a new file is a new document. The row keeps its title,
 * its optional links to a customer or a supplier and its archive time; the
 * file's path, name, SHA-256, size and page count are fixed by a trigger,
 * and a document that has been sent for signing is never deleted except by
 * the cascade from deleting the company.
 *
 * Additive only: a new table and two new triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('path', 255);
            $table->string('name', 255);
            $table->char('sha256', 64);
            $table->unsignedInteger('bytes');
            $table->unsignedSmallInteger('page_count');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'archived_at']);
            $table->index(['company_id', 'customer_id']);
            $table->index(['company_id', 'supplier_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION signing_documents_protect() RETURNS trigger AS $$
            BEGIN
                IF NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.path IS DISTINCT FROM OLD.path
                    OR NEW.name IS DISTINCT FROM OLD.name
                    OR NEW.sha256 IS DISTINCT FROM OLD.sha256
                    OR NEW.bytes IS DISTINCT FROM OLD.bytes
                    OR NEW.page_count IS DISTINCT FROM OLD.page_count
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'A signing document''s file cannot be changed; upload a new document (ADR-046 S7).';
                END IF;
                IF OLD.archived_at IS NOT NULL AND NEW.archived_at IS DISTINCT FROM OLD.archived_at THEN
                    RAISE EXCEPTION 'An archived signing document stays archived (ADR-046 S7).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_documents_protect BEFORE UPDATE ON signing_documents
                FOR EACH ROW EXECUTE FUNCTION signing_documents_protect();

            -- A document that has been sent for signing is evidence: never deleted, except by the cascade from deleting the company itself.
            CREATE OR REPLACE FUNCTION signing_documents_protect_delete() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF pg_trigger_depth() > 1 AND NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id) THEN
                    RETURN OLD;
                END IF;

                IF EXISTS (SELECT 1 FROM signing_envelopes WHERE company_id = OLD.company_id AND document_type = 'DOCUMENT' AND signable_id = OLD.id::text) THEN
                    RAISE EXCEPTION 'signing document % has been sent for signing and cannot be deleted', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN OLD;
            END;
            $$;

            CREATE OR REPLACE TRIGGER signing_documents_protect_delete BEFORE DELETE ON signing_documents
                FOR EACH ROW EXECUTE FUNCTION signing_documents_protect_delete();
        SQL);
    }
};
