<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The mileage log (körjournal, ADR-072): the vehicles a company's people
 * drive, the odometer at the start and end of each year, the trips, and
 * each person's saved routes. Off by default per company.
 *
 * Distances are stored in hectometres (0,1 km), so 25 kr per mil is exactly
 * 25 öre per hm. Places and purposes are encrypted at rest; a private trip
 * keeps only date and distance (KJ-R19). There is no position data.
 *
 * A trip that underlies a paid claim (PAID) or a posted voucher (BOOKED) is
 * underlag to a verifikation (BFL 5 kap. 6–7 §§) and is frozen by a
 * trigger: it can neither be changed nor deleted. A mistake is corrected in
 * a later claim by a correcting trip with a negative distance and a reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('mileage_log_enabled')->default(false);
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('label');
            $table->text('registration_number')->nullable();
            $table->string('fuel', 20)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_business_asset')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id']);
        });

        Schema::create('vehicle_year_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('start_odometer_km')->nullable();
            $table->unsignedInteger('end_odometer_km')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['vehicle_id', 'year']);
        });

        Schema::create('vehicle_trips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles');
            $table->foreignId('driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees');
            $table->date('trip_date');
            $table->string('kind', 10);
            $table->integer('distance_hm');
            $table->unsignedInteger('odometer_start_km')->nullable();
            $table->unsignedInteger('odometer_end_km')->nullable();
            $table->text('from_place')->nullable();
            $table->text('to_place')->nullable();
            $table->text('purpose')->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->boolean('all_fuel_paid_by_driver')->nullable();
            $table->boolean('charged_at_workplace')->default(false);
            $table->string('status', 10)->default('DRAFT');
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->uuid('corrects_trip_id')->nullable();
            $table->text('correction_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'vehicle_id', 'trip_date']);
            $table->index(['company_id', 'employee_id', 'status']);
            $table->index('expense_id');
            $table->index('voucher_id');
        });

        Schema::table('vehicle_trips', function (Blueprint $table) {
            $table->foreign('corrects_trip_id')->references('id')->on('vehicle_trips');
        });

        Schema::create('mileage_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('label');
            $table->unsignedInteger('distance_hm');
            $table->timestamps();

            $table->index(['company_id', 'user_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE vehicles ADD CONSTRAINT vehicles_kind_check CHECK (kind IN ('PRIVATE_CAR', 'COMPANY_CAR'));
            ALTER TABLE vehicle_trips ADD CONSTRAINT vehicle_trips_kind_check CHECK (kind IN ('BUSINESS', 'COMMUTE', 'PRIVATE'));
            ALTER TABLE vehicle_trips ADD CONSTRAINT vehicle_trips_status_check CHECK (status IN ('DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED', 'PAID', 'BOOKED'));
            ALTER TABLE vehicle_trips ADD CONSTRAINT vehicle_trips_distance_check CHECK (distance_hm > 0 OR (distance_hm < 0 AND corrects_trip_id IS NOT NULL));
            ALTER TABLE vehicle_trips ADD CONSTRAINT vehicle_trips_odometer_check CHECK (odometer_start_km IS NULL OR odometer_end_km IS NULL OR odometer_end_km >= odometer_start_km);
            ALTER TABLE vehicle_year_readings ADD CONSTRAINT vehicle_year_readings_odometer_check CHECK (start_odometer_km IS NULL OR end_odometer_km IS NULL OR end_odometer_km >= start_odometer_km);

            CREATE OR REPLACE FUNCTION vehicle_trips_protect_settled() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                -- A deleted login or project only loses its link; the trip itself stays as it was.
                IF TG_OP = 'UPDATE'
                   AND (to_jsonb(NEW) - 'driver_user_id' - 'project_id' - 'updated_at') = (to_jsonb(OLD) - 'driver_user_id' - 'project_id' - 'updated_at')
                   AND (NEW.driver_user_id IS NULL OR NEW.driver_user_id = OLD.driver_user_id)
                   AND (NEW.project_id IS NULL OR NEW.project_id = OLD.project_id) THEN
                    RETURN NEW;
                END IF;

                IF OLD.status IN ('PAID', 'BOOKED') THEN
                    RAISE EXCEPTION 'trip % underlies a payment or a posted voucher and cannot be %', OLD.id, lower(TG_OP)
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$;

            CREATE TRIGGER vehicle_trips_protect_settled
                BEFORE UPDATE OR DELETE ON vehicle_trips
                FOR EACH ROW EXECUTE FUNCTION vehicle_trips_protect_settled();
            SQL);
    }
};
