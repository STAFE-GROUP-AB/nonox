<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Planeringskalendern (ADR-071 §13): resources (workstations, downtime,
 * people's working hours, skills, overtime), the computed plan (runs with
 * slots and forecasts), a planner's locks and scenarios, deviations with
 * their impact on deliveries, and the delivery dates confirmed to customers.
 *
 * Nothing here is bookkeeping. Absence is never stored: the plan reads the
 * employee id, dates and extent of approved absences and nothing else, and a
 * deviation of type ABSENCE keeps only the employee id and the dates
 * (§13.7, GDPR art. 9).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Skills a person has and an operation needs (§13.2), kept in Tillverkning.
        Schema::create('production_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('production_employee_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained('production_skills')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'skill_id']);
            $table->index(['company_id', 'skill_id']);
        });

        // What an operation needs, and how long the work waits after it by default (drying, curing).
        Schema::table('operations', function (Blueprint $table) {
            $table->foreignId('required_skill_id')->nullable()->after('daily_capacity_minutes')->constrained('production_skills')->nullOnDelete();
            $table->unsignedInteger('wait_minutes')->default(0)->after('required_skill_id');
        });

        Schema::create('production_workstations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 20)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        // The operations a workstation serves; an operation served by a workstation needs one of them.
        Schema::create('production_workstation_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('workstation_id')->constrained('production_workstations')->cascadeOnDelete();
            $table->foreignId('operation_id')->constrained('operations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['workstation_id', 'operation_id']);
            $table->index(['company_id', 'operation_id']);
        });

        Schema::create('production_downtimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('workstation_id')->constrained('production_workstations')->cascadeOnDelete();
            $table->timestampTz('starts_at');
            // The expected end; none means until further notice.
            $table->timestampTz('ends_at')->nullable();
            $table->string('reason', 20);
            $table->string('source', 20)->default('MANUAL');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'starts_at']);
        });

        DB::statement("ALTER TABLE production_downtimes ADD CONSTRAINT production_downtimes_reason CHECK (reason IN ('BREAKDOWN', 'MAINTENANCE', 'OTHER'))");
        DB::statement("ALTER TABLE production_downtimes ADD CONSTRAINT production_downtimes_source CHECK (source IN ('MANUAL', 'TERMINAL'))");
        DB::statement('ALTER TABLE production_downtimes ADD CONSTRAINT production_downtimes_times CHECK (ends_at IS NULL OR ends_at > starts_at)');

        // A person's working week in Tillverkning, used when Planering is off (§13.2).
        Schema::create('production_work_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->string('break_start', 5)->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'weekday']);
            $table->index(['company_id', 'employee_id']);
        });

        DB::statement('ALTER TABLE production_work_patterns ADD CONSTRAINT production_work_patterns_weekday CHECK (weekday BETWEEN 1 AND 7)');

        // Extra working time a planner gave a person, beside the working week or the shifts.
        Schema::create('production_overtimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'starts_at']);
        });

        DB::statement('ALTER TABLE production_overtimes ADD CONSTRAINT production_overtimes_times CHECK (ends_at > starts_at)');

        // The order's operations (M4's table): workstation, the latest remaining estimate, waiting time after.
        Schema::table('production_order_operations', function (Blueprint $table) {
            $table->foreignId('workstation_id')->nullable()->after('operation_id')->constrained('production_workstations')->nullOnDelete();
            $table->unsignedInteger('remaining_minutes_override')->nullable()->after('planned_minutes');
            $table->unsignedInteger('wait_minutes')->nullable()->after('remaining_minutes_override');
        });

        // A planner's manual priority (§13.3 step 3): lower first, none last.
        Schema::table('production_orders', function (Blueprint $table) {
            $table->unsignedInteger('plan_priority')->nullable()->after('due_on');
        });

        // The delivery date confirmed to the customer (§10, §13.8).
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->date('confirmed_delivery_on')->nullable()->after('until_date');
        });

        Schema::create('production_plan_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('trigger', 20);
            // The moment the plan was made from, and how far it reaches.
            $table->timestampTz('planned_from');
            $table->timestampTz('horizon_until');
            $table->unsignedInteger('order_count')->default(0);
            $table->unsignedInteger('slot_count')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['company_id', 'id']);
        });

        Schema::create('production_plan_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('production_plan_runs')->cascadeOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('production_order_operation_id')->constrained('production_order_operations')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('workstation_id')->nullable()->constrained('production_workstations')->nullOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedInteger('minutes');
            // The stretches of working time it fills, as [start, end) epoch minutes.
            $table->jsonb('segments');
            $table->boolean('locked')->default(false);
            $table->unsignedTinyInteger('part')->default(0);

            $table->index(['run_id', 'starts_at']);
            $table->index(['run_id', 'production_order_id']);
        });

        Schema::create('production_plan_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('production_plan_runs')->cascadeOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignUuid('sales_document_id')->nullable()->constrained('sales_documents')->nullOnDelete();
            $table->date('material_ready_on')->nullable();
            $table->timestampTz('start_at')->nullable();
            $table->timestampTz('finish_at')->nullable();
            $table->date('forecast_delivery_on')->nullable();
            $table->date('promised_on')->nullable();
            $table->string('status', 10);
            $table->string('waits_for', 10);
            $table->jsonb('flags')->default('[]');

            $table->unique(['run_id', 'production_order_id']);
        });

        DB::statement("ALTER TABLE production_plan_forecasts ADD CONSTRAINT production_plan_forecasts_status CHECK (status IN ('GREEN', 'YELLOW', 'RED', 'GREY'))");
        DB::statement("ALTER TABLE production_plan_forecasts ADD CONSTRAINT production_plan_forecasts_waits_for CHECK (waits_for IN ('MATERIAL', 'CAPACITY', 'NOTHING'))");

        // A planner's hold on an operation (§13.6): LOCK keeps person, workstation and start; ASSIGN keeps the person; SPLIT gives minutes to a second person.
        Schema::create('production_plan_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('production_order_operation_id')->constrained('production_order_operations')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('workstation_id')->nullable()->constrained('production_workstations')->nullOnDelete();
            $table->timestampTz('starts_at')->nullable();
            $table->unsignedInteger('minutes')->nullable();
            // The run the lock was made in; kept while the lock exists.
            $table->foreignId('run_id')->nullable()->constrained('production_plan_runs')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['production_order_operation_id', 'kind']);
            $table->index(['company_id']);
        });

        DB::statement("ALTER TABLE production_plan_locks ADD CONSTRAINT production_plan_locks_kind CHECK (kind IN ('LOCK', 'ASSIGN', 'SPLIT'))");
        DB::statement("ALTER TABLE production_plan_locks ADD CONSTRAINT production_plan_locks_shape CHECK ((kind = 'LOCK' AND starts_at IS NOT NULL) OR (kind = 'ASSIGN') OR (kind = 'SPLIT' AND minutes > 0))");

        // "Tänk om" (§13.6): the changes as ids, enums, dates and minutes, computed in memory; expire after seven days.
        Schema::create('production_scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->jsonb('changes');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestamps();

            $table->index(['company_id', 'expires_at']);
        });

        Schema::create('production_deviations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('status', 10)->default('OPEN');
            // What caused it; for ABSENCE only the employee and the dates, never the absence record.
            $table->nullableMorphs('source');
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            // Ids, enums, dates and minutes only.
            $table->jsonb('details')->default('{}');
            $table->foreignId('run_before_id')->nullable()->constrained('production_plan_runs')->nullOnDelete();
            $table->foreignId('run_after_id')->nullable()->constrained('production_plan_runs')->nullOnDelete();
            // NO_IMPACT when the run after it moved no delivery.
            $table->string('resolution', 20)->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('handled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'created_at']);
        });

        DB::statement("ALTER TABLE production_deviations ADD CONSTRAINT production_deviations_type CHECK (type IN ('ABSENCE', 'PURCHASE_DELAY', 'SCRAP', 'OVERRUN', 'WORKSTATION_DOWN', 'QUANTITY_CHANGE', 'MATERIAL_SHORTAGE', 'LOCK_BROKEN'))");
        DB::statement("ALTER TABLE production_deviations ADD CONSTRAINT production_deviations_status CHECK (status IN ('OPEN', 'HANDLED', 'DISMISSED'))");

        Schema::create('production_deviation_impacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('deviation_id')->constrained('production_deviations')->cascadeOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignUuid('sales_document_id')->nullable()->constrained('sales_documents')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->date('promised_on')->nullable();
            // Null after: past the horizon.
            $table->date('forecast_before_on')->nullable();
            $table->date('forecast_after_on')->nullable();
            $table->integer('change_working_days')->nullable();
            $table->timestamps();

            $table->index(['deviation_id']);
        });

        Schema::create('production_customer_date_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('sales_document_id')->constrained('sales_documents')->cascadeOnDelete();
            $table->foreignId('deviation_id')->nullable()->constrained('production_deviations')->nullOnDelete();
            $table->date('old_on')->nullable();
            $table->date('new_on');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('message_to')->nullable();
            $table->timestampTz('message_sent_at')->nullable();
            $table->foreignId('message_sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'sales_document_id']);
        });
    }
};
