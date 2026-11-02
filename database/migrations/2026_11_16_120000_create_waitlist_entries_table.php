<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The waiting list of the open beta (ADR-038). Instance data, not company
 * data: an entry exists before any company does. The IP address is kept only
 * as a keyed hash, for spotting abuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 40)->nullable();
            $table->string('company_name');
            $table->string('org_number', 11)->nullable();
            $table->string('industry', 64);
            $table->string('employees', 16);
            $table->string('current_system', 64);
            $table->text('wants_to_test')->nullable();
            $table->text('why_switch')->nullable();
            $table->timestamp('consent_at');
            $table->string('status', 16)->default('PENDING')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('approved_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        DB::statement("ALTER TABLE waitlist_entries ADD CONSTRAINT waitlist_entries_status_check CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED', 'PARKED'))");
        DB::statement('ALTER TABLE waitlist_entries ADD CONSTRAINT waitlist_entries_email_lowercase CHECK (email = lower(email))');
        // One live entry per address; a rejected address may queue again.
        DB::statement("CREATE UNIQUE INDEX waitlist_entries_email_unique ON waitlist_entries (email) WHERE status <> 'REJECTED'");
    }
};
