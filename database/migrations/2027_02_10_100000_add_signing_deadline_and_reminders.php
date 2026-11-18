<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Deadline and reminders (ADR-046 amendment 2026-11-18, gap 9, package S9).
 * Only new nullable or defaulted columns, one new check and two triggers
 * that widen what is kept fixed; no table, column or constraint is dropped
 * or changed.
 *
 * - signing_envelopes.auto_reminders is false for every envelope that is
 *   already out: nothing is sent by a rule except what a sender switched on
 *   (ADR-046 principle 9). A new send sets it itself (default on).
 * - signing_envelopes.reminder_interval_days is 1, 2, 3 or 7, or null for the
 *   single reminder two days before the deadline.
 * - signing_envelopes.expired_at is set once, by signing:remind, when the
 *   deadline has passed and the expiry event has been written.
 * - crm_signing_requests.reminder_count and last_reminded_at are kept by the
 *   reminder action under the row lock; they only grow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_envelopes', function (Blueprint $table) {
            $table->boolean('auto_reminders')->default(false);
            $table->unsignedSmallInteger('reminder_interval_days')->nullable();
            $table->timestampTz('expired_at')->nullable();
        });

        Schema::table('crm_signing_requests', function (Blueprint $table) {
            $table->unsignedSmallInteger('reminder_count')->default(0);
            $table->timestampTz('last_reminded_at')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE signing_envelopes ADD CONSTRAINT signing_envelopes_reminder_interval_check
                CHECK (reminder_interval_days IS NULL OR reminder_interval_days IN (1, 2, 3, 7));

            -- The expiry time is set once and only while the envelope is out; a finished or expired envelope keeps
            -- its deadline and its reminder settings.
            CREATE OR REPLACE FUNCTION signing_envelopes_protect_deadline() RETURNS trigger AS $$
            BEGIN
                IF OLD.expired_at IS NOT NULL AND NEW.expired_at IS DISTINCT FROM OLD.expired_at THEN
                    RAISE EXCEPTION 'An envelope''s expiry time cannot be changed (ADR-046 S9).';
                END IF;

                IF NEW.expired_at IS NOT NULL AND OLD.expired_at IS NULL AND OLD.status <> 'OUT' THEN
                    RAISE EXCEPTION 'Only an envelope that is out can expire (ADR-046 S9).';
                END IF;

                IF (OLD.expired_at IS NOT NULL OR OLD.status IN ('COMPLETED', 'DECLINED', 'VOIDED'))
                    AND (NEW.deadline_at IS DISTINCT FROM OLD.deadline_at
                        OR NEW.auto_reminders IS DISTINCT FROM OLD.auto_reminders
                        OR NEW.reminder_interval_days IS DISTINCT FROM OLD.reminder_interval_days)
                THEN
                    RAISE EXCEPTION 'The deadline and reminders of an expired or finished envelope cannot be changed (ADR-046 S9).';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_envelopes_protect_deadline BEFORE UPDATE ON signing_envelopes
                FOR EACH ROW EXECUTE FUNCTION signing_envelopes_protect_deadline();

            -- Reminders only grow, and a signed or declined signer's reminder trail is fixed.
            CREATE OR REPLACE FUNCTION crm_signing_requests_protect_reminders() RETURNS trigger AS $$
            BEGIN
                IF NEW.reminder_count < OLD.reminder_count
                    OR (OLD.last_reminded_at IS NOT NULL AND (NEW.last_reminded_at IS NULL OR NEW.last_reminded_at < OLD.last_reminded_at))
                THEN
                    RAISE EXCEPTION 'A signer''s reminders cannot go back (ADR-046 S9).';
                END IF;

                IF OLD.status IN ('SIGNED', 'DECLINED')
                    AND (NEW.reminder_count IS DISTINCT FROM OLD.reminder_count OR NEW.last_reminded_at IS DISTINCT FROM OLD.last_reminded_at)
                THEN
                    RAISE EXCEPTION 'A signed or declined signing request cannot be reminded (ADR-046 S9).';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER crm_signing_requests_protect_reminders BEFORE UPDATE ON crm_signing_requests
                FOR EACH ROW EXECUTE FUNCTION crm_signing_requests_protect_reminders();
        SQL);
    }
};
