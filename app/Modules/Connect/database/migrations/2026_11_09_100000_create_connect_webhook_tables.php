<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * NONOX Connect webhooks (ADR-032): per-company subscriptions to events and
 * the delivery log.
 *
 * The signing secret is needed in plain text to sign every delivery, so it
 * is kept encrypted with the application key (Laravel's encrypted cast),
 * not hashed like API keys. The delivery log keeps the event we sent (ids
 * and amounts, never personal data) and the receiver's status code; the
 * receiver's answer is never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connect_webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('url', 2000);
            $table->string('description', 100)->nullable();
            $table->jsonb('events');
            $table->text('secret');
            // The first characters of the secret, to recognise it; the secret itself is shown once.
            $table->string('secret_prefix', 16);
            $table->timestampTz('disabled_at')->nullable();
            $table->string('disabled_reason', 40)->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'disabled_at']);
        });

        Schema::create('connect_webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('webhook_id')->constrained('connect_webhooks')->cascadeOnDelete();
            // The same for every subscription and every retry of one event (Nonox-Event-Id).
            $table->uuid('event_id');
            $table->string('event_type', 60);
            // The exact JSON sent (text, not jsonb, so every retry sends the same bytes).
            $table->text('body');
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->string('last_error', 120)->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['webhook_id', 'event_id']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['webhook_id', 'created_at']);
        });
    }
};
