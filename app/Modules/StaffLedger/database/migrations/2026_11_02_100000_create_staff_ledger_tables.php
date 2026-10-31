<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personalliggare (ADR-022): locations, the people who work there, the kiosk
 * devices that register them, the append-only ledger with a hash chain per
 * company and location, and proposals for time reports. Ledger rows cannot
 * be changed or removed: a trigger refuses UPDATE, DELETE and TRUNCATE. The
 * only removal is the retention prune, which sets a transaction-local flag
 * and moves the location's chain anchor (PruneStaffLedger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_ledger_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name');
            $table->string('address_line1');
            $table->string('postal_code', 10);
            $table->string('city');
            $table->string('business_type', 40);
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->unsignedInteger('radius_meters')->default(150);
            $table->boolean('kiosk_enabled')->default(true);
            $table->boolean('qr_enabled')->default(true);
            $table->boolean('mobile_enabled')->default(false);
            $table->char('chain_start_hash', 64)->nullable();
            $table->char('ledger_head_hash', 64)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'archived_at']);
        });

        Schema::create('staff_ledger_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->text('personal_number');
            $table->char('personal_number_index', 64);
            $table->string('email')->nullable();
            $table->string('language', 5)->default('sv');
            $table->string('employer_name')->nullable();
            $table->string('employer_org_number', 11)->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('photo_path')->nullable();
            $table->timestamp('photo_consent_at')->nullable();
            $table->char('pin_salt', 32)->nullable();
            $table->char('pin_verifier', 64)->nullable();
            $table->char('qr_secret_hash', 64)->nullable();
            $table->string('qr_nonce', 32)->nullable();
            $table->boolean('is_manager')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'personal_number_index']);
            $table->index(['company_id', 'archived_at']);
        });

        Schema::create('staff_ledger_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('staff_ledger_locations');
            $table->string('name');
            $table->char('token_hash', 64)->nullable()->unique();
            $table->char('pairing_code_hash', 64)->nullable()->unique();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->char('last_client_hash', 64)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('staff_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('staff_ledger_locations');
            $table->foreignId('person_id')->nullable()->constrained('staff_ledger_people');
            $table->string('type', 20);
            $table->string('direction', 3)->nullable();
            $table->string('correction', 10)->nullable();
            // No foreign key: the retention prune may remove an original before its correction.
            $table->unsignedBigInteger('corrects_entry_id')->nullable()->index();
            $table->string('method', 20);
            $table->string('person_name');
            $table->text('personal_number');
            $table->string('employer_name')->nullable();
            $table->string('employer_org_number', 11)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->foreignId('device_id')->nullable()->constrained('staff_ledger_devices');
            $table->unsignedBigInteger('device_sequence')->nullable();
            $table->char('client_hash', 64)->nullable();
            $table->integer('clock_skew_seconds')->nullable();
            $table->unsignedInteger('geo_distance_meters')->nullable();
            $table->jsonb('flags')->default('[]');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->text('reason')->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['device_id', 'device_sequence']);
            $table->index(['company_id', 'location_id', 'occurred_at']);
            $table->index(['company_id', 'person_id', 'occurred_at']);
        });

        Schema::create('staff_ledger_time_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('period_start');
            $table->date('period_end');
            $table->jsonb('days');
            $table->string('status', 10)->default('PROPOSED');
            $table->foreignId('proposed_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION staff_ledger_entries_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND current_setting('nonox.staff_ledger_prune', true) = 'on' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'staff_ledger_entries is append-only: % is not allowed', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER staff_ledger_entries_no_update_or_delete
                BEFORE UPDATE OR DELETE ON staff_ledger_entries
                FOR EACH ROW EXECUTE FUNCTION staff_ledger_entries_append_only();

            CREATE OR REPLACE FUNCTION staff_ledger_entries_no_truncate() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'staff_ledger_entries is append-only: TRUNCATE is not allowed'
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER staff_ledger_entries_truncate
                BEFORE TRUNCATE ON staff_ledger_entries
                FOR EACH STATEMENT EXECUTE FUNCTION staff_ledger_entries_no_truncate();
            SQL);
    }
};
