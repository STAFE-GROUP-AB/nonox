<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planering step 3 (PLANNING_MODULE.md §4): the employee's availability,
 * requests to claim, swap or drop a shift, and the secret iCal links. An
 * availability row is either recurring (a weekday) or for one date. A token
 * is kept only as its SHA-256 hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('date')->nullable();
            // ISO weekday, 1 = Monday … 7 = Sunday, for a recurring row.
            $table->unsignedSmallInteger('weekday')->nullable();
            // Wall-clock times; both null means the whole day.
            $table->string('from', 5)->nullable();
            $table->string('to', 5)->nullable();
            $table->string('kind', 12);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'employee_id']);
            $table->index(['company_id', 'date']);
        });

        DB::statement('ALTER TABLE planning_availability ADD CONSTRAINT planning_availability_day CHECK ((date IS NULL) <> (weekday IS NULL))');
        DB::statement('ALTER TABLE planning_availability ADD CONSTRAINT planning_availability_weekday CHECK (weekday IS NULL OR weekday BETWEEN 1 AND 7)');
        DB::statement("ALTER TABLE planning_availability ADD CONSTRAINT planning_availability_kind CHECK (kind IN ('AVAILABLE', 'UNAVAILABLE', 'PREFERRED'))");
        DB::statement('ALTER TABLE planning_availability ADD CONSTRAINT planning_availability_times CHECK (("from" IS NULL) = ("to" IS NULL))');

        Schema::create('planning_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('kind', 8);
            $table->foreignUuid('shift_id')->constrained('planning_shifts')->cascadeOnDelete();
            // The employee who asks; created_by is their login.
            $table->foreignId('requested_by')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('counterpart_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignUuid('counterpart_shift_id')->nullable()->constrained('planning_shifts')->nullOnDelete();
            $table->string('status', 10)->default('PENDING');
            $table->string('note', 500)->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'requested_by']);
        });

        DB::statement("ALTER TABLE planning_requests ADD CONSTRAINT planning_requests_kind CHECK (kind IN ('CLAIM', 'SWAP', 'DROP'))");
        DB::statement("ALTER TABLE planning_requests ADD CONSTRAINT planning_requests_status CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED', 'WITHDRAWN'))");
        // One pending request per employee and shift.
        DB::statement("CREATE UNIQUE INDEX planning_requests_one_pending ON planning_requests (shift_id, requested_by) WHERE status = 'PENDING'");

        Schema::create('planning_ical_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id']);
        });

        // At most one link in use per employee.
        DB::statement('CREATE UNIQUE INDEX planning_ical_tokens_one_active ON planning_ical_tokens (employee_id) WHERE revoked_at IS NULL');
    }
};
