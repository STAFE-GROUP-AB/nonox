<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM stage 6, calendar (ADR-049).
 *
 * - An activity gets a length, a place and a calendar sequence: the
 *   sequence goes up on every change a calendar must see (time, length,
 *   title, done), so subscribed and synced calendars replace their copy.
 *   `invited_at` records that an invitation went to the meeting's person.
 * - `crm_calendar_feeds`: a user's secret subscription link in one company.
 *   Only the SHA-256 hash of the token is kept. At most one link in use per
 *   user and company.
 * - `crm_calendar_connections`: a user's own Google or Microsoft calendar,
 *   with the OAuth tokens encrypted by the model. One per user and company.
 * - `crm_calendar_events`: which event in the connected calendar is which
 *   activity. Only these events are ever read back from the calendar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_activities', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('location', 255)->nullable();
            $table->unsignedInteger('calendar_sequence')->default(0);
            $table->timestampTz('invited_at')->nullable();
        });

        DB::statement('ALTER TABLE crm_activities ADD CONSTRAINT crm_activities_duration_check CHECK (duration_minutes IS NULL OR duration_minutes BETWEEN 5 AND 1440)');

        Schema::create('crm_calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            // Off: "Möte: <företag>" and a link only. On: the subject, the text and the people.
            $table->boolean('include_details')->default(false);
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id']);
        });

        DB::statement('CREATE UNIQUE INDEX crm_calendar_feeds_one_active ON crm_calendar_feeds (company_id, user_id) WHERE revoked_at IS NULL');

        Schema::create('crm_calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // GOOGLE or MICROSOFT.
            $table->string('provider', 20);
            // Encrypted by the model: the account the user signed in with, and the tokens.
            $table->text('account_email')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->string('calendar_id', 512)->nullable();
            $table->string('calendar_name', 255)->nullable();
            // Google's syncToken or Microsoft's deltaLink: where the next read of changes starts.
            $table->text('sync_cursor')->nullable();
            // As for the link: off writes "Möte: <företag>" and a link only.
            $table->boolean('include_details')->default(false);
            // ACTIVE, ERROR (the user must connect again) or REVOKED (being removed).
            $table->string('status', 10)->default('ACTIVE');
            $table->string('last_error', 255)->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });

        DB::statement("ALTER TABLE crm_calendar_connections ADD CONSTRAINT crm_calendar_connections_provider CHECK (provider IN ('GOOGLE', 'MICROSOFT'))");
        DB::statement("ALTER TABLE crm_calendar_connections ADD CONSTRAINT crm_calendar_connections_status CHECK (status IN ('ACTIVE', 'ERROR', 'REVOKED'))");

        Schema::create('crm_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('crm_calendar_connections')->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained('crm_activities')->cascadeOnDelete();
            $table->string('external_id', 1024);
            // The activity's calendar_sequence when it was last written to, or read from, the calendar.
            $table->unsignedInteger('synced_sequence')->default(0);
            $table->timestampTz('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'activity_id']);
            $table->unique(['connection_id', 'external_id']);
        });
    }
};
