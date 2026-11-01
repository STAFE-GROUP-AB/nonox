<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A company's own named check of an agreement version against its sources
 * (ADR-033 §2, docs/avtal/README.md §5). Append-only, like
 * payroll_rule_verifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreement_rule_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('agreement_rule_version_id')->constrained('agreement_rule_versions');
            $table->foreignId('verified_by')->constrained('users');
            $table->string('verifier_name');
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->unique(['company_id', 'agreement_rule_version_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER agreement_rule_verifications_append_only
                BEFORE UPDATE OR DELETE ON agreement_rule_verifications
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            SQL);
    }
};
