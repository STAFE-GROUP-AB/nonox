<?php

declare(strict_types=1);

use App\Domain\Signing\Retention\SigningRetention;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Retention, legal hold and erasure of signing envelopes (ADR-046 amendment
 * 2026-11-18, gap 11, package S16). Additive: new columns, new checks, one
 * new trigger and three replaced delete-trigger functions.
 *
 * - teams.signing_retention_years: how long a signed document that is not
 *   räkenskapsinformation is kept after completion, 7–25 years (default 10,
 *   owner decision 12).
 * - signing_envelopes.retention_class (ACCOUNTING, OTHER, UNSIGNED) and
 *   retention_until (the last day the envelope is kept, Stockholm date) are
 *   set when the envelope finishes or expires. The class only moves up
 *   (UNSIGNED → OTHER → ACCOUNTING) and the date only moves later; an
 *   ACCOUNTING envelope is kept at least until 31 December seven years after
 *   the end of the fiscal year it was completed in (BFL 7 kap. 2 §), checked
 *   here against fiscal_years.
 * - signing_envelopes.retention_hold (bevarandespärr) with a reason key,
 *   time and user. A held envelope is never deleted, by the prune or by an
 *   erasure.
 *
 * The delete triggers. Before, a signed signer row was never deleted and a
 * completed envelope only by the company cascade. Now the envelope's own
 * delete trigger is the gate for the whole envelope: it lets an envelope go
 * once its retention_until has passed and it is not held; an envelope that
 * no one has signed may go as before, and one with a decline younger than
 * twelve months only as an erasure (the transaction-local setting
 * nonox.signing_erasure names the envelope). Its signer rows and copy
 * recipients follow by the foreign key's cascade; their own triggers accept
 * the cascade once the envelope row is gone, and keep their old rules for a
 * direct delete (plus: never while the envelope is held).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedSmallInteger('signing_retention_years')->default(10);
        });

        Schema::table('signing_envelopes', function (Blueprint $table) {
            $table->string('retention_class', 12)->nullable();
            $table->date('retention_until')->nullable();
            $table->boolean('retention_hold')->default(false);
            $table->string('retention_hold_reason', 20)->nullable();
            $table->timestampTz('retention_hold_at')->nullable();
            $table->foreignId('retention_hold_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['company_id', 'retention_until']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE teams ADD CONSTRAINT teams_signing_retention_years_check
                CHECK (signing_retention_years BETWEEN 7 AND 25);

            ALTER TABLE signing_envelopes ADD CONSTRAINT signing_envelopes_retention_class_check
                CHECK (retention_class IS NULL OR retention_class IN ('ACCOUNTING', 'OTHER', 'UNSIGNED'));
            -- Class and date go together, and only on an envelope that is finished or has expired.
            ALTER TABLE signing_envelopes ADD CONSTRAINT signing_envelopes_retention_pair_check
                CHECK ((retention_class IS NULL) = (retention_until IS NULL));
            ALTER TABLE signing_envelopes ADD CONSTRAINT signing_envelopes_retention_finished_check
                CHECK (retention_until IS NULL OR status IN ('COMPLETED', 'DECLINED', 'VOIDED') OR expired_at IS NOT NULL);
            -- A signed class only for a completed envelope.
            ALTER TABLE signing_envelopes ADD CONSTRAINT signing_envelopes_retention_signed_check
                CHECK (retention_class IS NULL OR retention_class = 'UNSIGNED' OR status = 'COMPLETED');
            ALTER TABLE signing_envelopes ADD CONSTRAINT signing_envelopes_retention_hold_check
                CHECK (CASE WHEN retention_hold THEN retention_hold_reason IN ('SKATTEREVISION', 'TVIST', 'ANNAT') AND retention_hold_at IS NOT NULL
                       ELSE retention_hold_reason IS NULL AND retention_hold_at IS NULL END);

            -- The BFL floor of an ACCOUNTING envelope: 31 December seven years after the end of the fiscal year the
            -- envelope was completed in (BFL 7 kap. 2 §), or null when that fiscal year is not in the company's books.
            CREATE OR REPLACE FUNCTION signing_envelopes_accounting_floor(env signing_envelopes) RETURNS date
            LANGUAGE sql STABLE
            SET search_path = public, pg_temp
            AS $$
                SELECT make_date(extract(year FROM fy.end_date)::int + 7, 12, 31)
                FROM fiscal_years fy
                WHERE env.retention_class = 'ACCOUNTING'
                  AND env.completed_at IS NOT NULL
                  AND fy.company_id = env.company_id
                  AND (env.completed_at AT TIME ZONE 'Europe/Stockholm')::date BETWEEN fy.start_date AND fy.end_date
                ORDER BY fy.end_date DESC
                LIMIT 1;
            $$;

            CREATE OR REPLACE FUNCTION signing_envelopes_protect_retention() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                floor_date date;
            BEGIN
                IF OLD.retention_until IS NOT NULL AND (NEW.retention_until IS NULL OR NEW.retention_until < OLD.retention_until) THEN
                    RAISE EXCEPTION 'A signing envelope''s retention date never moves earlier (ADR-046 S16).';
                END IF;

                IF OLD.retention_class IS NOT NULL AND NEW.retention_class IS DISTINCT FROM OLD.retention_class
                   AND NOT ((OLD.retention_class = 'UNSIGNED' AND NEW.retention_class IN ('OTHER', 'ACCOUNTING'))
                            OR (OLD.retention_class = 'OTHER' AND NEW.retention_class = 'ACCOUNTING')) THEN
                    RAISE EXCEPTION 'A signing envelope''s retention class only moves up (ADR-046 S16).';
                END IF;

                floor_date := signing_envelopes_accounting_floor(NEW);

                IF floor_date IS NOT NULL AND NEW.retention_until < floor_date THEN
                    RAISE EXCEPTION 'Räkenskapsinformation is kept until seven years after its fiscal year (BFL 7 kap. 2 §, ADR-046 S16).';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE TRIGGER signing_envelopes_protect_retention BEFORE UPDATE ON signing_envelopes
                FOR EACH ROW EXECUTE FUNCTION signing_envelopes_protect_retention();

            -- The gate for deleting an envelope and, by the cascade, everything in it.
            CREATE OR REPLACE FUNCTION signing_envelopes_protect_delete() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF pg_trigger_depth() > 1 AND NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id) THEN
                    RETURN OLD;
                END IF;

                IF OLD.retention_hold THEN
                    RAISE EXCEPTION 'signing envelope % is on legal hold and cannot be deleted', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.retention_until IS NOT NULL AND OLD.retention_until < (now() AT TIME ZONE 'Europe/Stockholm')::date THEN
                    RETURN OLD;
                END IF;

                IF OLD.status = 'COMPLETED' OR EXISTS (SELECT 1 FROM crm_signing_requests WHERE envelope_id = OLD.id AND status = 'SIGNED') THEN
                    RAISE EXCEPTION 'signed signing envelope % is kept until its retention date', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF EXISTS (SELECT 1 FROM crm_signing_requests WHERE envelope_id = OLD.id AND status = 'DECLINED'
                           AND (declined_at IS NULL OR declined_at >= now() - interval '12 months'))
                   AND current_setting('nonox.signing_erasure', true) IS DISTINCT FROM OLD.id::text THEN
                    RAISE EXCEPTION 'declined signing envelope % cannot be deleted before the prune period', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN OLD;
            END;
            $$;

            -- A signer row: with its envelope (whose trigger decided) or its company; directly as before, never while held.
            CREATE OR REPLACE FUNCTION crm_signing_requests_protect_delete() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF pg_trigger_depth() > 1 AND NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id) THEN
                    RETURN OLD;
                END IF;

                IF pg_trigger_depth() > 1 AND OLD.envelope_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM signing_envelopes WHERE id = OLD.envelope_id) THEN
                    RETURN OLD;
                END IF;

                IF OLD.envelope_id IS NOT NULL AND EXISTS (SELECT 1 FROM signing_envelopes WHERE id = OLD.envelope_id AND retention_hold) THEN
                    RAISE EXCEPTION 'signing request % belongs to an envelope on legal hold', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
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

            -- Who got the copy of a completed envelope stays, except with its envelope or its company.
            CREATE OR REPLACE FUNCTION signing_copy_recipients_protect_delete() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF pg_trigger_depth() > 1
                   AND (NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id)
                        OR NOT EXISTS (SELECT 1 FROM signing_envelopes WHERE id = OLD.envelope_id)) THEN
                    RETURN OLD;
                END IF;

                IF EXISTS (SELECT 1 FROM signing_envelopes WHERE id = OLD.envelope_id AND status = 'COMPLETED') THEN
                    RAISE EXCEPTION 'a copy recipient of completed signing envelope % cannot be deleted', OLD.envelope_id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN OLD;
            END;
            $$;
        SQL);

        // Every envelope that is already finished or expired gets its class and date, rows updated in these columns only.
        // Calls the app's own rule on purpose (as S2's chain backfill does): one rule for the backfill and for new
        // envelopes. A later change to SigningRetention::backfill changes what this migration would do on a fresh
        // database, never on one where it has run; the prune also runs it for envelopes an old worker finished.
        SigningRetention::backfill();
    }
};
