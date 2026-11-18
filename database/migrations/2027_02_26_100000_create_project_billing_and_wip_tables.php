<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Billing plans and work in progress on projects (ADR-081 gap 6, E8).
 *
 * teams.fixed_price_method: the company's one method for all fixed-price
 * jobs at year end (K2 6.15, K3 23.31). Until now it lived only in the work
 * order module's settings; it is now the company's, read by both the work
 * orders and the projects. Null means "not chosen here" and falls back to the
 * module setting, so nothing changes on deploy.
 *
 * project_billing_milestones: a project's billing plan, each milestone an
 * amount or a share of the contract sum (exactly one), with a status. When
 * it is invoiced, the draft invoice is linked; deleting that draft frees the
 * milestone again (nullOnDelete). Issued invoices cannot be deleted.
 *
 * project_wip_snapshots: the figures a person saw when they asked for WIP
 * draft vouchers for a project on a balance sheet date, and the drafts made.
 * Append-only by its own trigger. The voucher ids carry no foreign key: the
 * drafts may be deleted, and the snapshot stays as the record of what was
 * proposed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->string('fixed_price_method', 20)->nullable();
        });

        DB::statement("ALTER TABLE teams ADD CONSTRAINT teams_fixed_price_method CHECK (fixed_price_method IS NULL OR fixed_price_method IN ('NOT_SET', 'K2_MAIN', 'K2_ALTERNATIVE', 'K3_MAIN', 'K3_COMPLETION'))");

        Schema::create('project_billing_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('description', 255);
            $table->date('due_on')->nullable();
            $table->bigInteger('amount_ore')->nullable();
            $table->unsignedInteger('share_bp')->nullable();
            $table->string('status', 10)->default('PLANNED');
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'project_id', 'position']);
            $table->index(['company_id', 'invoice_id']);
        });

        DB::statement('ALTER TABLE project_billing_milestones ADD CONSTRAINT project_billing_milestones_one_amount CHECK ((amount_ore IS NULL) <> (share_bp IS NULL))');
        DB::statement('ALTER TABLE project_billing_milestones ADD CONSTRAINT project_billing_milestones_amount CHECK (amount_ore IS NULL OR amount_ore > 0)');
        DB::statement('ALTER TABLE project_billing_milestones ADD CONSTRAINT project_billing_milestones_share CHECK (share_bp IS NULL OR share_bp BETWEEN 1 AND 10000)');
        DB::statement("ALTER TABLE project_billing_milestones ADD CONSTRAINT project_billing_milestones_status CHECK (status IN ('PLANNED', 'READY', 'INVOICED'))");

        Schema::create('project_wip_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->date('as_of');
            $table->string('method', 20);
            $table->bigInteger('contract_ore');
            $table->bigInteger('costs_ore');
            $table->bigInteger('estimated_cost_ore')->nullable();
            $table->unsignedInteger('degree_bp')->nullable();
            $table->bigInteger('earned_ore')->nullable();
            $table->bigInteger('invoiced_ore');
            // Signed: positive to 1620 (earned, not invoiced), negative to 2450 (invoiced ahead).
            $table->bigInteger('adjustment_ore');
            $table->unsignedSmallInteger('revenue_account');
            $table->uuid('voucher_id')->nullable();
            $table->uuid('reversal_voucher_id')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('created_at');

            $table->index(['company_id', 'as_of']);
            $table->index(['company_id', 'project_id', 'as_of']);
        });

        DB::statement('ALTER TABLE project_wip_snapshots ADD CONSTRAINT project_wip_snapshots_degree CHECK (degree_bp IS NULL OR degree_bp BETWEEN 0 AND 10000)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION project_wip_snapshots_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Project WIP snapshots cannot be changed or deleted.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER project_wip_snapshots_append_only
                BEFORE UPDATE OR DELETE ON project_wip_snapshots
                FOR EACH ROW EXECUTE FUNCTION project_wip_snapshots_append_only();
            SQL);
    }
};
