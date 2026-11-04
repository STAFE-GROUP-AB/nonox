<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM stage 4 (ADR-047): call lists worked one company at a time, and the
 * notices of changes in the company register that have become tasks.
 * Company-scoped; the register itself (company_registry) is instance data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_call_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name');
            // The seller who works the list.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // How the list was built (the search filters or the account filters), for the list's header.
            $table->jsonb('criteria')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'archived_at']);
        });

        Schema::create('crm_call_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('call_list_id')->constrained('crm_call_lists')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('crm_accounts')->cascadeOnDelete();
            $table->unsignedInteger('position');
            // PENDING, DONE or SKIPPED.
            $table->string('status', 10)->default('PENDING');
            $table->unsignedSmallInteger('attempts')->default(0);
            // The last call's outcome (CallOutcome) and the activity that logged it.
            $table->string('outcome', 20)->nullable();
            $table->foreignId('activity_id')->nullable()->constrained('crm_activities')->nullOnDelete();
            $table->timestampTz('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['call_list_id', 'account_id']);
            $table->index(['company_id', 'call_list_id', 'status', 'position']);
        });

        Schema::create('crm_registry_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('crm_accounts')->cascadeOnDelete();
            // The change in the instance's register; kept as an id only (the change is pruned after 400 days).
            $table->unsignedBigInteger('registry_change_id');
            $table->foreignId('activity_id')->nullable()->constrained('crm_activities')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'account_id', 'registry_change_id']);
        });
    }
};
