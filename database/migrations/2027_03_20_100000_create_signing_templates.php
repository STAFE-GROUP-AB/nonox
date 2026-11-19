<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Templates with roles (ADR-046 amendment 2026-11-18, gap 8, package S14).
 *
 * signing_templates: a PDF (document type DOCUMENT) or a document type
 * (QUOTE, AGREEMENT), with defaults for the last day, reminders, copy
 * recipients, language and confidentiality. A used template is archived,
 * never deleted (the action decides; an envelope only remembers its id).
 *
 * signing_template_roles: the roles of a template ("Kund", "Säljare") with
 * their step, their check and whether the company's own side takes the role.
 *
 * signing_fields gets its template side: template_id + role_key instead of
 * envelope_id + signing_request_id. A template's fields change freely; an
 * envelope copies them when it goes out. The rows are created and removed by
 * the app, so the grant is no longer NoDelete: the delete trigger still
 * refuses to remove a field of an envelope that is out.
 *
 * Additive: new tables, one nullable column on envelopes, two columns on
 * signing_fields and the two keys made nullable. The triggers are replaced by
 * versions that keep every rule of S6 and add the template side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('document_type', 10);
            $table->string('path')->nullable();
            $table->string('file_name', 120)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->unsignedSmallInteger('deadline_days')->nullable();
            $table->boolean('auto_reminders')->default(true);
            $table->unsignedSmallInteger('reminder_days')->nullable();
            $table->string('language', 2)->default('sv');
            $table->boolean('confidential')->default(false);
            $table->jsonb('copies')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'archived_at']);
        });

        DB::statement("ALTER TABLE signing_templates ADD CONSTRAINT signing_templates_type_check CHECK (document_type IN ('DOCUMENT', 'QUOTE', 'AGREEMENT'))");
        DB::statement("ALTER TABLE signing_templates ADD CONSTRAINT signing_templates_file_check CHECK ((document_type = 'DOCUMENT' AND path IS NOT NULL AND sha256 IS NOT NULL AND page_count IS NOT NULL) OR (document_type <> 'DOCUMENT' AND path IS NULL))");

        Schema::create('signing_template_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->uuid('template_id');
            $table->foreign('template_id')->references('id')->on('signing_templates')->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('label', 60);
            $table->unsignedSmallInteger('step');
            $table->string('verification', 20)->default('NONE');
            $table->boolean('internal')->default(false);
            $table->unsignedSmallInteger('position');

            $table->unique(['template_id', 'key']);
            $table->index(['company_id', 'template_id']);
        });

        DB::statement('ALTER TABLE signing_template_roles ADD CONSTRAINT signing_template_roles_step_check CHECK (step BETWEEN 1 AND 10)');

        Schema::table('signing_envelopes', function (Blueprint $table) {
            $table->uuid('template_id')->nullable();
            $table->index('template_id');
        });

        Schema::table('signing_fields', function (Blueprint $table) {
            $table->uuid('template_id')->nullable();
            $table->string('role_key', 40)->nullable();
            $table->index('template_id');
        });

        DB::statement('ALTER TABLE signing_fields ALTER COLUMN envelope_id DROP NOT NULL');
        DB::statement('ALTER TABLE signing_fields ALTER COLUMN signing_request_id DROP NOT NULL');
        DB::statement('ALTER TABLE signing_fields ADD CONSTRAINT signing_fields_template_fk FOREIGN KEY (template_id, role_key) REFERENCES signing_template_roles (template_id, key) ON DELETE CASCADE');
        DB::statement('ALTER TABLE signing_fields ADD CONSTRAINT signing_fields_side_check CHECK ((envelope_id IS NOT NULL AND signing_request_id IS NOT NULL AND template_id IS NULL AND role_key IS NULL) OR (envelope_id IS NULL AND signing_request_id IS NULL AND template_id IS NOT NULL AND role_key IS NOT NULL))');

        $this->guards();
    }

    /** CREATE OR REPLACE, so running it again gives the same result. */
    public function guards(): void
    {
        DB::unprepared(<<<'SQL'
            -- A template's file and type never change once stored; a new file is a new template.
            CREATE OR REPLACE FUNCTION signing_templates_protect() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF NEW.company_id IS DISTINCT FROM OLD.company_id
                   OR NEW.document_type IS DISTINCT FROM OLD.document_type
                   OR NEW.path IS DISTINCT FROM OLD.path
                   OR NEW.sha256 IS DISTINCT FROM OLD.sha256
                   OR NEW.page_count IS DISTINCT FROM OLD.page_count
                   OR NEW.bytes IS DISTINCT FROM OLD.bytes
                   OR NEW.file_name IS DISTINCT FROM OLD.file_name THEN
                    RAISE EXCEPTION 'A signing template''s file and type cannot be changed (ADR-046 S14).';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE TRIGGER signing_templates_protect BEFORE UPDATE ON signing_templates
                FOR EACH ROW EXECUTE FUNCTION signing_templates_protect();

            -- The template an envelope was set up from: of its own company, set once at the send and fixed after.
            CREATE OR REPLACE FUNCTION signing_envelopes_template_protect() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND OLD.template_id IS NOT NULL AND NEW.template_id IS DISTINCT FROM OLD.template_id THEN
                    RAISE EXCEPTION 'The template of an envelope cannot be changed (ADR-046 S14).';
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.template_id IS NULL AND NEW.template_id IS NOT NULL AND OLD.status IN ('COMPLETED', 'DECLINED', 'VOIDED') THEN
                    RAISE EXCEPTION 'The template of a finished envelope cannot be set (ADR-046 S14).';
                END IF;

                -- Checked when the id is set, never on later updates: a template deleted after the send must not freeze the envelope.
                IF NEW.template_id IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.template_id IS DISTINCT FROM OLD.template_id) AND NOT EXISTS (SELECT 1 FROM signing_templates WHERE id = NEW.template_id AND company_id = NEW.company_id) THEN
                    RAISE EXCEPTION 'An envelope''s template belongs to its own company (ADR-046 S14).';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE TRIGGER signing_envelopes_template_protect BEFORE INSERT OR UPDATE ON signing_envelopes
                FOR EACH ROW EXECUTE FUNCTION signing_envelopes_template_protect();

            -- A role belongs to a template of its own company.
            CREATE OR REPLACE FUNCTION signing_template_roles_protect() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM signing_templates WHERE id = NEW.template_id AND company_id = NEW.company_id) THEN
                    RAISE EXCEPTION 'A role belongs to a template of its own company (ADR-046 S14).';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE TRIGGER signing_template_roles_protect BEFORE INSERT OR UPDATE ON signing_template_roles
                FOR EACH ROW EXECUTE FUNCTION signing_template_roles_protect();

            -- The S6 rules for an envelope's field, unchanged, with the template side first: a template's field
            -- belongs to a template of its own company and changes freely.
            CREATE OR REPLACE FUNCTION signing_fields_protect() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                envelope_status text;
            BEGIN
                IF NEW.template_id IS NOT NULL THEN
                    IF NOT EXISTS (SELECT 1 FROM signing_templates WHERE id = NEW.template_id AND company_id = NEW.company_id) THEN
                        RAISE EXCEPTION 'A template field belongs to a template of its own company (ADR-046 S14).';
                    END IF;

                    IF TG_OP = 'UPDATE' AND (OLD.template_id IS DISTINCT FROM NEW.template_id OR OLD.company_id IS DISTINCT FROM NEW.company_id) THEN
                        RAISE EXCEPTION 'A template field stays with its template (ADR-046 S14).';
                    END IF;

                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.template_id IS NOT NULL THEN
                    RAISE EXCEPTION 'A template field cannot become an envelope field (ADR-046 S14).';
                END IF;

                SELECT status INTO envelope_status
                    FROM signing_envelopes WHERE id = NEW.envelope_id AND company_id = NEW.company_id;

                IF envelope_status IS NULL THEN
                    RAISE EXCEPTION 'A signing field belongs to an envelope of its own company (ADR-046 S6).';
                END IF;

                IF NOT EXISTS (SELECT 1 FROM crm_signing_requests
                               WHERE id = NEW.signing_request_id AND envelope_id = NEW.envelope_id AND company_id = NEW.company_id) THEN
                    RAISE EXCEPTION 'A signing field belongs to a signer of its own envelope (ADR-046 S6).';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF envelope_status = 'DRAFT' OR (envelope_status = 'OUT'
                        AND NOT EXISTS (SELECT 1 FROM signing_link_tokens t JOIN crm_signing_requests r ON r.id = t.signing_request_id
                                        WHERE r.envelope_id = NEW.envelope_id)
                        AND NOT EXISTS (SELECT 1 FROM crm_signing_events e JOIN crm_signing_requests r ON r.id = e.signing_request_id
                                        WHERE r.envelope_id = NEW.envelope_id)
                        AND NOT EXISTS (SELECT 1 FROM crm_signing_events WHERE envelope_id = NEW.envelope_id)) THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'Fields are placed before an envelope goes out (ADR-046 S6).';
                END IF;

                IF envelope_status = 'DRAFT' AND OLD.envelope_id = NEW.envelope_id AND OLD.company_id = NEW.company_id THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'A signing field is frozen once its envelope is out (ADR-046 S6).';
            END;
            $$;

            CREATE OR REPLACE FUNCTION signing_fields_protect_delete() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF OLD.template_id IS NOT NULL THEN
                    RETURN OLD;
                END IF;

                IF pg_trigger_depth() > 1
                   AND (NOT EXISTS (SELECT 1 FROM signing_envelopes WHERE id = OLD.envelope_id)
                        OR NOT EXISTS (SELECT 1 FROM crm_signing_requests WHERE id = OLD.signing_request_id)
                        OR NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id)) THEN
                    RETURN OLD;
                END IF;

                IF EXISTS (SELECT 1 FROM signing_envelopes WHERE id = OLD.envelope_id AND status = 'DRAFT') THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'A signing field is removed only with its envelope (ADR-046 S6).'
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;
        SQL);
    }
};
