<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The operator company (ADR-044, review round): the sync never links to a
 * customer, CRM account or person it did not create. When it finds one with
 * the same organisation number or e-mail address, the link waits in
 * NEEDS_REVIEW with the candidate's ids until an HQ admin chooses Koppla
 * (link to it) or Skapa ny (create a new one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operator_links', function (Blueprint $table) {
            $table->foreignId('review_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('review_account_id')->nullable()->constrained('crm_accounts')->nullOnDelete();
            $table->foreignId('review_person_id')->nullable()->constrained('crm_people')->nullOnDelete();
            $table->string('review_decision', 8)->nullable();
        });

        DB::statement('ALTER TABLE operator_links DROP CONSTRAINT operator_links_status_check');
        DB::statement("ALTER TABLE operator_links ADD CONSTRAINT operator_links_status_check CHECK (status IN ('PENDING', 'SYNCED', 'SUPPRESSED', 'FAILED', 'NEEDS_REVIEW'))");
        DB::statement("ALTER TABLE operator_links ADD CONSTRAINT operator_links_review_decision_check CHECK (review_decision IS NULL OR review_decision IN ('LINK', 'NEW'))");
    }
};
