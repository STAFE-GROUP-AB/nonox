<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The vacation liability (semesterlöneskuld) as booked with each posted
 * payroll run, per employee. The next run books the change since the last
 * snapshot. Rows are only ever added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacation_liability_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->unique()->constrained('payroll_runs');
            $table->date('as_of');
            $table->bigInteger('liability_ore');
            $table->bigInteger('contributions_ore');
            $table->jsonb('employees');
            $table->timestamps();

            $table->index(['company_id', 'as_of']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION vacation_liability_snapshots_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'vacation liability snapshots cannot be changed or deleted'
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER vacation_liability_snapshots_append_only
                BEFORE UPDATE OR DELETE ON vacation_liability_snapshots
                FOR EACH ROW EXECUTE FUNCTION vacation_liability_snapshots_append_only();
            SQL);
    }
};
