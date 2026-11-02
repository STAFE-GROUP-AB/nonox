<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planering step 4 (PLANNING_MODULE.md §4, §8): one attestation per employee
 * and day. It holds what was planned and worked, the manager's own actual
 * time when the staff ledger is off or wrong (the ledger itself is never
 * changed), the payroll basis in minutes per pay code, and the time reports
 * it wrote when it was sent to payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_attestations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('date');
            $table->string('status', 10)->default('OPEN');
            $table->unsignedInteger('planned_minutes')->default(0);
            $table->unsignedInteger('actual_minutes')->default(0);
            // The manager's actual time: [{start, end, break_minutes}], or [] for no work. Null = from the ledger (or the plan).
            $table->jsonb('actual_override')->nullable();
            $table->jsonb('basis')->nullable();
            $table->jsonb('deviations')->nullable();
            // What the manager attested: planned and actual intervals and the OB split, frozen with the basis.
            $table->jsonb('details')->nullable();
            $table->jsonb('time_report_ids')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('attested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attested_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'date']);
            $table->index(['company_id', 'date', 'status']);
        });

        DB::statement("ALTER TABLE planning_attestations ADD CONSTRAINT planning_attestations_status_check CHECK (status IN ('OPEN', 'ATTESTED', 'SENT'))");
    }
};
