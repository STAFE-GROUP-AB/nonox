<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Margin and floor warnings (ADR-082 P7, §9, decision 12). Additive only.
 *
 * - teams.min_contribution_bp: the company's lowest contribution margin
 *   (lägsta täckningsgrad) in basis points; null = no floor, the default.
 * - teams.price_floor_policy: what a manual price under that floor does,
 *   WARN (a warning only) or REQUIRE_APPROVAL (someone with
 *   prices:approve-below-floor approves the document first).
 * - price_floor_approvals: the approval of one version of an invoice, quote
 *   or order (the fingerprint of its rows), asked for and decided by people.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->unsignedSmallInteger('min_contribution_bp')->nullable();
            $table->string('price_floor_policy', 20)->default('WARN');
        });

        Schema::create('price_floor_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('document_type', 10);
            $table->uuid('document_id');
            $table->char('fingerprint', 64);
            $table->string('status', 10)->default('PENDING');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'document_type', 'document_id']);
            $table->index(['company_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE teams ADD CONSTRAINT teams_price_floor_policy_check
                CHECK (price_floor_policy IN ('WARN', 'REQUIRE_APPROVAL'));
            ALTER TABLE teams ADD CONSTRAINT teams_min_contribution_bp_check
                CHECK (min_contribution_bp IS NULL OR min_contribution_bp BETWEEN 0 AND 9900);

            ALTER TABLE price_floor_approvals ADD CONSTRAINT price_floor_approvals_type_check
                CHECK (document_type IN ('INVOICE', 'QUOTE', 'ORDER'));
            ALTER TABLE price_floor_approvals ADD CONSTRAINT price_floor_approvals_status_check
                CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED', 'WITHDRAWN'));
            ALTER TABLE price_floor_approvals ADD CONSTRAINT price_floor_approvals_decided_check
                CHECK (status IN ('PENDING', 'WITHDRAWN') OR (decided_by IS NOT NULL AND decided_at IS NOT NULL));
            CREATE UNIQUE INDEX price_floor_approvals_one_pending
                ON price_floor_approvals (company_id, document_type, document_id) WHERE status = 'PENDING';
        SQL);
    }
};
