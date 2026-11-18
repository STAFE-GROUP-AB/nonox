<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company document archive (ADR-081 gap 9, package E19): documents that
 * belong to no feature (contracts, insurance, permits, policies), their
 * versions, tags and links to records.
 *
 * Retention is guarded in three places, like the vouchers (AGENTS.md §1.2):
 * the policy, the actions and these triggers. A document cannot be deleted
 * while it is on legal hold, before its retain_until date, or while it is a
 * contract that still runs (no end date). Its retain_until date never moves
 * earlier while it is still in force, so a document kept as
 * räkenskapsinformation cannot be reclassified out of its seven years.
 * Versions are append-only: never changed, removed only together with a
 * document that may be removed. A deleted company takes everything with it
 * (its row in teams is already gone when the cascade arrives).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('category', 60);
            $table->string('retention_class', 20);
            $table->foreignId('fiscal_year_id')->nullable()->constrained('fiscal_years');
            $table->date('contract_ends_on')->nullable();
            $table->unsignedSmallInteger('contract_retention_years')->nullable();
            $table->date('keep_until')->nullable();
            $table->date('retain_until')->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->timestamp('legal_hold_at')->nullable();
            $table->foreignId('legal_hold_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('contains_personal_data');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'retention_class']);
            $table->index(['company_id', 'category']);
            $table->index(['company_id', 'fiscal_year_id']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('path');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('original_name');
            $table->text('extracted_text')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['document_id', 'version']);
            $table->index(['company_id', 'document_id']);
            $table->index('path');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('document_versions')->nullOnDelete();
        });

        Schema::create('document_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('linkable_type', 30);
            $table->unsignedBigInteger('linkable_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_id', 'linkable_type', 'linkable_id']);
            $table->index(['company_id', 'linkable_type', 'linkable_id']);
        });

        Schema::create('document_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('tag', 40);
            $table->timestamps();

            $table->unique(['document_id', 'tag']);
            $table->index(['company_id', 'tag']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE documents ADD CONSTRAINT documents_retention_class_check
                CHECK (retention_class IN ('ACCOUNTING', 'CONTRACT', 'PERSONNEL', 'OTHER'));
            ALTER TABLE documents ADD CONSTRAINT documents_accounting_year_check
                CHECK (retention_class <> 'ACCOUNTING' OR (fiscal_year_id IS NOT NULL AND retain_until IS NOT NULL));
            ALTER TABLE documents ADD CONSTRAINT documents_contract_years_check
                CHECK (contract_retention_years IS NULL OR contract_retention_years <= 30);
            ALTER TABLE document_versions ADD CONSTRAINT document_versions_sha256_check
                CHECK (sha256 ~ '^[0-9a-f]{64}$');

            CREATE INDEX documents_title_search ON documents USING gin (to_tsvector('swedish', title));
            CREATE INDEX document_versions_text_search ON document_versions USING gin (to_tsvector('swedish', coalesce(extracted_text, '')));

            CREATE OR REPLACE FUNCTION documents_retention_blocks(doc documents) RETURNS boolean
            LANGUAGE sql STABLE
            SET search_path = public, pg_temp
            AS $$
                SELECT doc.legal_hold
                    OR (doc.retain_until IS NOT NULL AND doc.retain_until >= current_date)
                    OR (doc.retention_class = 'CONTRACT' AND doc.contract_ends_on IS NULL);
            $$;

            CREATE OR REPLACE FUNCTION documents_guard_retention() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    -- Only the cascade from a deleted company: its row in teams is already gone.
                    IF EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id) AND documents_retention_blocks(OLD) THEN
                        RAISE EXCEPTION 'The document is kept: legal hold, retention period or a running contract (ADR-081 E19).';
                    END IF;
                    RETURN OLD;
                END IF;

                IF NEW.company_id <> OLD.company_id THEN
                    RAISE EXCEPTION 'A document never moves to another company.';
                END IF;

                IF OLD.retain_until IS NOT NULL AND OLD.retain_until >= current_date
                   AND (NEW.retain_until IS NULL OR NEW.retain_until < OLD.retain_until) THEN
                    RAISE EXCEPTION 'The retention date of a document never moves earlier (ADR-081 E19).';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER documents_guard_retention BEFORE UPDATE OR DELETE ON documents
                FOR EACH ROW EXECUTE FUNCTION documents_guard_retention();

            CREATE OR REPLACE FUNCTION document_versions_append_only() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                parent documents;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    -- A deleted login only loses its link; the version itself never changes.
                    IF (to_jsonb(NEW) - 'uploaded_by') = (to_jsonb(OLD) - 'uploaded_by')
                       AND (NEW.uploaded_by IS NULL OR NEW.uploaded_by = OLD.uploaded_by) THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'Document versions are append-only (ADR-081 E19).';
                END IF;

                SELECT * INTO parent FROM documents WHERE id = OLD.document_id;

                IF FOUND AND EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id) AND documents_retention_blocks(parent) THEN
                    RAISE EXCEPTION 'The document is kept: legal hold, retention period or a running contract (ADR-081 E19).';
                END IF;

                RETURN OLD;
            END;
            $$;

            CREATE TRIGGER document_versions_append_only BEFORE UPDATE OR DELETE ON document_versions
                FOR EACH ROW EXECUTE FUNCTION document_versions_append_only();
        SQL);
    }
};
