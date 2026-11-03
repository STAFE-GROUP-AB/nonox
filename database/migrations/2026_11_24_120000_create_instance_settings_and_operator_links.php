<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The operator company (ADR-044). Both tables are instance data, not company
 * data, and only /hq and the sync read them.
 *
 * - instance_settings: key/value settings of the installation, edited by HQ
 *   admins (the operator company, where customers and queue applicants go,
 *   whether the queue and self-registration are open).
 * - operator_links: which customer, CRM account and CRM person in the target
 *   company came from which company or waiting-list entry, so the sync
 *   never makes duplicates and knows what it may erase again. It holds ids,
 *   flags and SHA-256 hashes of the values last pushed, never the values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instance_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->jsonb('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('operator_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('source_company_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->foreignId('waitlist_entry_id')->nullable()->constrained('waitlist_entries')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('crm_account_id')->nullable()->constrained('crm_accounts')->nullOnDelete();
            $table->foreignId('crm_person_id')->nullable()->constrained('crm_people')->nullOnDelete();
            $table->boolean('customer_created')->default(false);
            $table->boolean('account_created')->default(false);
            $table->boolean('person_created')->default(false);
            $table->string('status', 16)->default('PENDING');
            $table->jsonb('pushed')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['target_company_id', 'source_company_id']);
            $table->unique(['target_company_id', 'waitlist_entry_id']);
            $table->index('failed_at');
        });

        DB::statement("ALTER TABLE operator_links ADD CONSTRAINT operator_links_status_check CHECK (status IN ('PENDING', 'SYNCED', 'SUPPRESSED', 'FAILED'))");
    }
};
