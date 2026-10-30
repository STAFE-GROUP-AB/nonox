<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A company's own check of a payroll rule against its source. Rules are
 * shared by every company on an installation, so each company's responsible
 * person confirms them for that company. A check is never removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_rule_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('payroll_rule_version_id')->constrained('payroll_rule_versions');
            $table->foreignId('verified_by')->constrained('users');
            $table->string('verifier_name');
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->unique(['company_id', 'payroll_rule_version_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER payroll_rule_verifications_append_only
                BEFORE UPDATE OR DELETE ON payroll_rule_verifications
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            SQL);
    }
};
