<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM stage 2 (ADR-043): pipelines with up to seven open stages, deals with
 * a value in öre, a probability through their stage, a close date and an
 * owner, loss reasons, the stage history that gives the sales cycle, goals
 * per seller and month, and the link from an activity to the deal it is the
 * next step of. Won and lost are a deal's status, not stages.
 *
 * Every company that already has CRM on gets the default pipeline and loss
 * reasons here; a company that turns CRM on later gets them from the
 * module's enable hook (EnsurePipelineDefaults). The values are written out
 * here so that this migration never changes when the action does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_pipelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'archived_at']);
        });

        Schema::create('crm_pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained('crm_pipelines')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort')->default(0);
            // Whole percent, 0–100: the chance that a deal in this stage is won.
            $table->unsignedSmallInteger('probability')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'pipeline_id', 'sort']);
        });

        DB::statement('ALTER TABLE crm_pipeline_stages ADD CONSTRAINT crm_pipeline_stages_probability_check CHECK (probability BETWEEN 0 AND 100)');

        Schema::create('crm_loss_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'archived_at']);
        });

        Schema::create('crm_deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            // A prospect erased under GDPR takes its deals with it.
            $table->foreignId('account_id')->constrained('crm_accounts')->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('crm_people')->nullOnDelete();
            $table->foreignId('pipeline_id')->constrained('crm_pipelines');
            $table->foreignId('stage_id')->constrained('crm_pipeline_stages');
            $table->string('title');
            // Öre, excluding VAT.
            $table->bigInteger('value_ore')->default(0);
            $table->date('expected_close_date')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            // OPEN, WON or LOST.
            $table->string('status', 10)->default('OPEN');
            $table->timestampTz('won_at')->nullable();
            $table->timestampTz('lost_at')->nullable();
            $table->foreignId('loss_reason_id')->nullable()->constrained('crm_loss_reasons')->nullOnDelete();
            $table->string('loss_note', 1000)->nullable();
            // The ledger documents the deal led to, when someone links them.
            $table->foreignUuid('quote_id')->nullable()->constrained('sales_documents')->nullOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained('sales_documents')->nullOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            // A lost deal: when to try again, and when the "Vinn tillbaka" task was made.
            $table->date('win_back_at')->nullable();
            $table->timestampTz('win_back_task_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status', 'pipeline_id', 'stage_id']);
            $table->index(['company_id', 'owner_id', 'status']);
            $table->index(['company_id', 'account_id']);
            $table->index(['company_id', 'status', 'won_at']);
            $table->index(['company_id', 'status', 'win_back_at']);
        });

        DB::statement('ALTER TABLE crm_deals ADD CONSTRAINT crm_deals_value_check CHECK (value_ore >= 0)');
        DB::statement("ALTER TABLE crm_deals ADD CONSTRAINT crm_deals_status_check CHECK (status IN ('OPEN', 'WON', 'LOST'))");

        Schema::create('crm_deal_stage_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('deal_id')->constrained('crm_deals')->cascadeOnDelete();
            // Null on the first row (the deal was created in to_stage_id).
            $table->foreignId('from_stage_id')->nullable()->constrained('crm_pipeline_stages')->nullOnDelete();
            $table->foreignId('to_stage_id')->nullable()->constrained('crm_pipeline_stages')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('changed_at');

            $table->index(['company_id', 'deal_id', 'changed_at']);
        });

        Schema::create('crm_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // The first day of the month.
            $table->date('month');
            // MEETINGS, QUOTES or ORDER_VALUE.
            $table->string('metric', 20);
            // A count, or öre for ORDER_VALUE.
            $table->bigInteger('target');
            $table->timestamps();

            $table->unique(['company_id', 'user_id', 'month', 'metric']);
            $table->index(['company_id', 'month']);
        });

        DB::statement('ALTER TABLE crm_goals ADD CONSTRAINT crm_goals_target_check CHECK (target >= 0)');

        Schema::table('crm_activities', function (Blueprint $table) {
            $table->foreignId('deal_id')->nullable()->constrained('crm_deals')->nullOnDelete();
            $table->index(['company_id', 'deal_id', 'done_at']);
        });

        Schema::table('crm_accounts', function (Blueprint $table) {
            // A lost account: when to try again, and when the "Vinn tillbaka" task was made.
            $table->date('win_back_at')->nullable();
            $table->timestampTz('win_back_task_at')->nullable();
        });

        $this->backfill();
    }

    /** The default pipeline and loss reasons for every company that already has CRM on. */
    private function backfill(): void
    {
        $now = now();
        $companies = DB::table('company_modules')->where('module_key', 'crm')->where('enabled', true)->pluck('company_id');

        foreach ($companies as $companyId) {
            if (! DB::table('crm_pipelines')->where('company_id', $companyId)->exists()) {
                $pipelineId = DB::table('crm_pipelines')->insertGetId([
                    'company_id' => $companyId,
                    'name' => 'Försäljning',
                    'sort' => 0,
                    'is_default' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ([['Ny kontakt', 10], ['Behov', 25], ['Offert', 50], ['Förhandling', 75]] as $sort => [$name, $probability]) {
                    DB::table('crm_pipeline_stages')->insert([
                        'company_id' => $companyId,
                        'pipeline_id' => $pipelineId,
                        'name' => $name,
                        'sort' => $sort,
                        'probability' => $probability,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            if (! DB::table('crm_loss_reasons')->where('company_id', $companyId)->exists()) {
                foreach (['Priset', 'Valde en annan leverantör', 'Ingen budget', 'Fel tidpunkt', 'Slutade svara', 'Annat'] as $sort => $name) {
                    DB::table('crm_loss_reasons')->insert([
                        'company_id' => $companyId,
                        'name' => $name,
                        'sort' => $sort,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }
};
