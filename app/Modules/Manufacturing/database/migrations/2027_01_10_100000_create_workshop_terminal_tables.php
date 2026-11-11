<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Verkstadsterminalen (ADR-071 §7): shared tablets in the workshop where a
 * worker taps their picture, types a personal code and reports hours on an
 * open production order. Four small tables, all prefixed workshop_:
 *
 * - workshop_people: who appears on the terminal. Not NONOX users and not
 *   the staff ledger's people: a name, an optional consented photo and a
 *   code verifier (PBKDF2, never the code). No personal number.
 * - workshop_devices: a tablet paired to one company with a device token
 *   (only its SHA-256 is kept), as the staff ledger's kiosk is.
 * - workshop_time_entries: who reported what, when and on which device.
 *   Append-only; a correction is a new row with negative minutes. Each row
 *   points to the production_labour_entries row (source TERMINAL) that
 *   carries the cost, so the ledger of M4 stays free of personal data. The
 *   only change allowed is removing the person after the retention period.
 * - workshop_waiting_flags: an operation flagged as waiting for material or
 *   for work. It names the device, never a person.
 *
 * production_labour_entries and production_order_operations are not
 * touched: the planning calendar (M6) adds its own columns to the latter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            // A small square JPEG on the private disk, stored only with the person's consent.
            $table->string('photo_path')->nullable();
            $table->timestamp('photo_consent_at')->nullable();
            // The personal code is never stored: a salt and a PBKDF2 verifier (the staff ledger's Pin).
            $table->string('code_salt', 32)->nullable();
            $table->string('code_verifier', 128)->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'archived_at']);
        });

        Schema::create('workshop_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('token_hash', 64)->nullable()->unique();
            $table->string('pairing_code_hash', 64)->nullable()->index();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            // A device that has reported time is hidden, not deleted: each entry keeps the device it came from.
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'removed_at']);
        });

        Schema::create('workshop_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            // Nulled after the retention period (24 months); the minutes stay as production cost.
            $table->foreignId('person_id')->nullable()->constrained('workshop_people');
            $table->foreignId('device_id')->constrained('workshop_devices');
            $table->foreignId('production_order_id')->constrained('production_orders');
            $table->foreignId('production_order_operation_id')->constrained('production_order_operations');
            $table->foreignId('production_labour_entry_id')->unique()->constrained('production_labour_entries');
            // Signed: a correction is a new row that cancels an earlier one.
            $table->integer('minutes');
            $table->date('worked_on');
            $table->foreignId('corrects_entry_id')->nullable()->unique()->constrained('workshop_time_entries');
            $table->timestamp('entered_at')->useCurrent();

            $table->index(['company_id', 'person_id', 'worked_on']);
            $table->index(['company_id', 'entered_at']);
            $table->index(['production_order_operation_id']);
        });

        DB::statement('ALTER TABLE workshop_time_entries ADD CONSTRAINT workshop_time_entries_minutes CHECK (minutes <> 0 AND minutes BETWEEN -960 AND 960)');

        // The only change a row may ever get: the person removed once the retention period has passed.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION workshop_time_entries_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'A terminal time entry is never deleted.';
                END IF;

                IF NEW.person_id IS NOT NULL
                    OR (to_jsonb(NEW) - 'person_id') IS DISTINCT FROM (to_jsonb(OLD) - 'person_id') THEN
                    RAISE EXCEPTION 'A terminal time entry is never changed; only its person may be removed.';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);
        DB::unprepared('CREATE TRIGGER workshop_time_entries_guard BEFORE UPDATE OR DELETE ON workshop_time_entries FOR EACH ROW EXECUTE FUNCTION workshop_time_entries_guard()');

        Schema::create('workshop_waiting_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders');
            $table->foreignId('production_order_operation_id')->constrained('production_order_operations');
            $table->string('kind', 20);
            // Free text, short; the form tells the worker not to write names.
            $table->string('reason', 200);
            $table->foreignId('raised_by_device_id')->constrained('workshop_devices');
            $table->timestamp('raised_at')->useCurrent();
            $table->timestamp('cleared_at')->nullable();
            $table->foreignId('cleared_by_device_id')->nullable()->constrained('workshop_devices');
            $table->foreignId('cleared_by_user_id')->nullable()->constrained('users');

            $table->index(['company_id', 'cleared_at']);
            $table->index(['production_order_id']);
        });

        DB::statement("ALTER TABLE workshop_waiting_flags ADD CONSTRAINT workshop_waiting_flags_kind CHECK (kind IN ('MATERIAL', 'WORK'))");
        // One open flag per operation; a new one replaces the old by clearing it first.
        DB::statement('CREATE UNIQUE INDEX workshop_waiting_flags_one_open ON workshop_waiting_flags (production_order_operation_id) WHERE cleared_at IS NULL');
    }
};
