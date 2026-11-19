<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Fields in the PDF per signer (ADR-046, amendment 2026-11-18, gap 2,
 * package S6).
 *
 * signing_fields: where a signer fills something in (a signature, initials,
 * a date, a name, a text, a number, a checkbox, a dropdown or a radio
 * button), with its page, its rectangle and the page's size in 1/100 PDF
 * points from the page's lower left corner, and its rules. A field is placed
 * while the envelope is a draft, or while it is being sent: before the first
 * event of its chain. After that it is frozen.
 *
 * signing_field_values: what a signer filled in, written once in the
 * transaction that signs, and evidence from then on (its hash is in the
 * envelope's chain). Never changed; removed only with its field, its signer
 * row, its envelope or its company, by the foreign keys' cascade.
 *
 * Additive only: two new tables and their triggers. Nothing that exists is
 * changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->uuid('envelope_id');
            $table->foreign('envelope_id')->references('id')->on('signing_envelopes')->cascadeOnDelete();
            $table->foreignId('signing_request_id')->constrained('crm_signing_requests')->cascadeOnDelete();
            $table->string('type', 12);
            $table->unsignedSmallInteger('page');
            // 1/100 PDF points from the lower left corner of the page's crop box, after rotation.
            $table->integer('x');
            $table->integer('y');
            $table->integer('width');
            $table->integer('height');
            $table->integer('page_width');
            $table->integer('page_height');
            $table->string('label', 100);
            $table->boolean('required')->default(false);
            $table->jsonb('options')->nullable();
            $table->string('group_key', 40)->nullable();
            $table->jsonb('rules')->nullable();
            $table->unsignedSmallInteger('position');
            $table->timestampTz('created_at');

            $table->index(['company_id', 'envelope_id']);
            $table->index('envelope_id');
            $table->index('signing_request_id');
        });

        DB::statement("ALTER TABLE signing_fields ADD CONSTRAINT signing_fields_type_check CHECK (type IN ('SIGNATURE', 'INITIALS', 'DATE', 'NAME', 'TEXT', 'NUMBER', 'CHECKBOX', 'DROPDOWN', 'RADIO'))");
        DB::statement('ALTER TABLE signing_fields ADD CONSTRAINT signing_fields_geometry_check CHECK (page BETWEEN 1 AND 100 AND x >= 0 AND y >= 0 AND width > 0 AND height > 0 AND page_width > 0 AND page_height > 0 AND x + width <= page_width AND y + height <= page_height)');

        Schema::create('signing_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('field_id')->constrained('signing_fields')->cascadeOnDelete();
            $table->foreignId('signing_request_id')->constrained('crm_signing_requests')->cascadeOnDelete();
            $table->text('value')->nullable();
            // A drawn signature or initials, encrypted (the encrypted cast), as on the signer row.
            $table->text('image')->nullable();
            $table->timestampTz('filled_at', 0);

            $table->unique('field_id');
            $table->index(['company_id', 'signing_request_id']);
        });

        DB::statement('ALTER TABLE signing_field_values ADD CONSTRAINT signing_field_values_value_check CHECK ((value IS NOT NULL OR image IS NOT NULL) AND (value IS NULL OR char_length(value) <= 500))');

        $this->guards();
    }

    /** The triggers. CREATE OR REPLACE, so running it again gives the same result. */
    public function guards(): void
    {
        DB::unprepared(<<<'SQL'
            -- A field belongs to a signer of its own envelope and company, and is placed while the envelope is a
            -- draft or while it is being sent: before any link is issued and before any event of its signers (so
            -- no signer has been shown the document yet). Once placed it is changed only while the envelope is a draft.
            CREATE OR REPLACE FUNCTION signing_fields_protect() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                envelope_status text;
            BEGIN
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

            CREATE OR REPLACE TRIGGER signing_fields_protect BEFORE INSERT OR UPDATE ON signing_fields
                FOR EACH ROW EXECUTE FUNCTION signing_fields_protect();

            -- Removed while the envelope is a draft, or with its envelope, its signer row or its company (the cascade).
            CREATE OR REPLACE FUNCTION signing_fields_protect_delete() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
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

            CREATE OR REPLACE TRIGGER signing_fields_protect_delete BEFORE DELETE ON signing_fields
                FOR EACH ROW EXECUTE FUNCTION signing_fields_protect_delete();

            -- A value is written by the field's own signer, once, while that signer is still pending (the signing
            -- transaction writes the values before it marks the signer row signed). Never changed.
            CREATE OR REPLACE FUNCTION signing_field_values_protect() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'A signing field value is evidence and never changes (ADR-046 S6).';
                END IF;

                IF NOT EXISTS (SELECT 1 FROM signing_fields
                               WHERE id = NEW.field_id AND signing_request_id = NEW.signing_request_id AND company_id = NEW.company_id) THEN
                    RAISE EXCEPTION 'A signer fills in only their own fields (ADR-046 S6).';
                END IF;

                IF NOT EXISTS (SELECT 1 FROM crm_signing_requests WHERE id = NEW.signing_request_id AND status = 'PENDING') THEN
                    RAISE EXCEPTION 'Field values are written while the signer signs (ADR-046 S6).';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE TRIGGER signing_field_values_protect BEFORE INSERT OR UPDATE ON signing_field_values
                FOR EACH ROW EXECUTE FUNCTION signing_field_values_protect();

            -- Removed only with its field, its signer row or its company (the cascade).
            CREATE OR REPLACE FUNCTION signing_field_values_protect_delete() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF pg_trigger_depth() > 1
                   AND (NOT EXISTS (SELECT 1 FROM signing_fields WHERE id = OLD.field_id)
                        OR NOT EXISTS (SELECT 1 FROM crm_signing_requests WHERE id = OLD.signing_request_id)
                        OR NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id)) THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'A signing field value is removed only with its envelope (ADR-046 S6).'
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE OR REPLACE TRIGGER signing_field_values_protect_delete BEFORE DELETE ON signing_field_values
                FOR EACH ROW EXECUTE FUNCTION signing_field_values_protect_delete();
        SQL);
    }
};
