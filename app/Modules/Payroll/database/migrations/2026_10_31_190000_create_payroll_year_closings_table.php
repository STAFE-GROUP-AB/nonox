<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A closed payroll calendar year (PAYROLL_MODULE.md B.14): no run may be
 * paid in it any more, so its accumulators and the yearly totals stay as
 * declared. The totals are kept as they were when the year was closed. A
 * closing is never changed or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_year_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->jsonb('totals');
            $table->foreignId('closed_by')->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'year']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payroll_year_closings_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'payroll year closing % cannot be changed or removed', OLD.id
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER payroll_year_closings_append_only
                BEFORE UPDATE OR DELETE ON payroll_year_closings
                FOR EACH ROW EXECUTE FUNCTION payroll_year_closings_append_only();
            SQL);
    }
};
