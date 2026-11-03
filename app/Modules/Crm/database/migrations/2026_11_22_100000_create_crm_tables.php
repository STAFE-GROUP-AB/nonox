<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM stage 1 (ADR-043): the accounts the company deals with (prospects and
 * customers), the people at them and their roles, and the activities with a
 * next step. An account that is a customer points at its row in customers;
 * the ledger fields stay there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            // Set when the account is a customer; a customer erased under GDPR leaves the account without one.
            $table->foreignId('customer_id')->nullable()->unique()->constrained('customers')->nullOnDelete();
            $table->string('name');
            $table->string('org_number', 13)->nullable();
            // COMPANY or PERSON.
            $table->string('kind', 10)->default('COMPANY');
            // PROSPECT, ACTIVE, INACTIVE or LOST.
            $table->string('status', 10)->default('PROSPECT');
            // A status set by hand is kept until a later ledger event; lost needs a reason.
            $table->timestampTz('status_set_manually_at')->nullable();
            $table->string('lost_reason', 500)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('crm_accounts')->nullOnDelete();
            $table->string('sni_code', 10)->nullable();
            $table->string('website')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city')->nullable();
            $table->char('country', 2)->default('SE');
            $table->string('source', 100)->nullable();
            $table->jsonb('tags')->default(DB::raw("'[]'::jsonb"));
            // The last time someone did anything with the account: the clock of the GDPR prune.
            $table->timestampTz('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'owner_id']);
            $table->index(['company_id', 'city']);
            $table->index(['company_id', 'name']);
        });

        DB::statement('CREATE INDEX crm_accounts_tags_index ON crm_accounts USING gin (tags)');

        Schema::create('crm_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name');
            $table->string('title')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();
            // AGREEMENT, LEGITIMATE_INTEREST or CONSENT (GDPR art. 6.1 b, f, a).
            $table->string('legal_basis', 30);
            $table->string('legal_basis_note', 1000)->nullable();
            $table->date('consent_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'name']);
        });

        Schema::create('crm_account_person', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('crm_accounts')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('crm_people')->cascadeOnDelete();
            // DECISION_MAKER, AMBASSADOR, INFLUENCER, USER, INVOICE_RECIPIENT or OTHER.
            $table->string('role', 20)->default('OTHER');
            $table->timestamps();

            $table->unique(['account_id', 'person_id']);
            $table->index(['company_id', 'person_id']);
        });

        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('crm_accounts')->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('crm_people')->nullOnDelete();
            // NOTE, CALL, MEETING, EMAIL or TASK.
            $table->string('type', 10);
            // Calls only: REACHED, NO_ANSWER, LEFT_MESSAGE or WRONG_NUMBER.
            $table->string('call_outcome', 20)->nullable();
            $table->string('subject');
            $table->text('body')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('done_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The timeline of an account, and its next open step.
            $table->index(['company_id', 'account_id', 'done_at']);
            // Mina aktiviteter: one user's open activities by due time.
            $table->index(['company_id', 'assigned_to', 'done_at', 'due_at']);
            $table->index(['company_id', 'person_id']);
        });
    }
};
