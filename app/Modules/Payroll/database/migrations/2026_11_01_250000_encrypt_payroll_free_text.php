<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Free text that can hold health or personnel details is encrypted at rest:
 * reasons for rejected absences and outlays, reasons for salary changes,
 * and the reason an employment ended (which was only in the audit log
 * before, where it could never be removed). payroll_inputs.note stays as
 * it is: approved runs protect those rows with a trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_changes', function (Blueprint $table) {
            $table->text('reason')->nullable()->change();
        });

        Schema::table('employments', function (Blueprint $table) {
            $table->text('end_reason')->nullable();
        });

        foreach (['absences' => 'rejection_reason', 'expenses' => 'rejection_reason', 'salary_changes' => 'reason'] as $table => $column) {
            DB::table($table)->whereNotNull($column)->orderBy('id')->each(function (object $row) use ($table, $column): void {
                DB::table($table)->where('id', $row->id)->update([$column => Crypt::encryptString((string) $row->{$column})]);
            });
        }
    }
};
