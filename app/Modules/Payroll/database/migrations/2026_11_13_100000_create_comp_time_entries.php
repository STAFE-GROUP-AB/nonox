<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comp time (komptid, gap G8 in docs/avtal/gap-analys.md): overtime or
 * mertid taken as leave instead of money. One row per change of an
 * employee's balance, in minutes of leave: EARNED (+, from an attested
 * period in Planering), TAKEN (−, leave on a date), PAID_OUT (−, paid in the
 * payroll run of its date's month) and ADJUSTMENT (±, an opening balance or
 * a correction). The balance is the sum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comp_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('date');
            $table->string('kind', 12);
            $table->integer('minutes');
            // For EARNED: the overtime or mertid minutes behind it, the factor and the code it was measured on.
            $table->unsignedInteger('worked_minutes')->nullable();
            $table->string('factor', 10)->nullable();
            $table->string('pay_code', 30)->nullable();
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'date']);
            $table->index(['source_type', 'source_id']);
        });

        DB::statement("ALTER TABLE comp_time_entries ADD CONSTRAINT comp_time_entries_kind_check CHECK (kind IN ('EARNED', 'TAKEN', 'PAID_OUT', 'ADJUSTMENT'))");
        DB::statement("ALTER TABLE comp_time_entries ADD CONSTRAINT comp_time_entries_sign_check CHECK ((kind = 'EARNED' AND minutes > 0) OR (kind IN ('TAKEN', 'PAID_OUT') AND minutes < 0) OR (kind = 'ADJUSTMENT' AND minutes <> 0))");
    }
};
