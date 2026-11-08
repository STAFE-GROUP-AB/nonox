<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The public status page (ADR-078). All three tables are instance data, not
 * company data: no company_id, and nothing in them may name a company or a
 * person.
 *
 * - status_heartbeats: when a scheduled job or an operator script (backup,
 *   restore test) last ran, and last succeeded. One row per key.
 * - status_incidents / status_incident_updates: the operator's notes about
 *   a disruption or planned maintenance, with the history of how it went.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_heartbeats', function (Blueprint $table) {
            $table->string('key', 50)->primary();
            $table->timestampTz('last_run_at');
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
        });

        Schema::create('status_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->string('severity', 20);
            $table->string('status', 20);
            $table->timestampTz('started_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['resolved_at', 'started_at']);
        });

        Schema::create('status_incident_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_incident_id')->constrained('status_incidents')->cascadeOnDelete();
            $table->string('status', 20);
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');
        });
    }
};
