<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * NONOX Connect (ADR-032): the OAuth 2.0 tables Laravel Passport reads and
 * writes (same columns as Passport 13's own migrations, which are not
 * published), each token tied to a grant, plus the grants themselves, API
 * keys for scripts and the idempotency store.
 *
 * A grant is one consent: one user let one app into one company with some
 * scopes. Every authorization code, access token and refresh token issued
 * under it carries its id, so revoking the grant (or detecting a reused
 * refresh token) revokes the whole family at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->nullableMorphs('owner');
            $table->string('name');
            $table->string('secret')->nullable();
            $table->string('provider')->nullable();
            $table->text('redirect_uris');
            $table->text('grant_types');
            $table->boolean('revoked');
            $table->timestamps();
        });

        Schema::create('connect_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('oauth_clients')->cascadeOnDelete();
            $table->jsonb('scopes');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 40)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'revoked_at']);
        });

        Schema::create('oauth_auth_codes', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->index();
            $table->foreignUuid('client_id');
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
            $table->foreignUuid('connect_grant_id')->nullable()->index()->constrained('connect_grants')->cascadeOnDelete();
        });

        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->foreignUuid('client_id');
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestamps();
            $table->dateTime('expires_at')->nullable();
            $table->foreignUuid('connect_grant_id')->nullable()->index()->constrained('connect_grants')->cascadeOnDelete();
        });

        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->char('access_token_id', 80)->index();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
            $table->foreignUuid('connect_grant_id')->nullable()->index()->constrained('connect_grants')->cascadeOnDelete();
        });

        Schema::create('connect_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            // The first characters of the key, to recognise it in the list; the key itself is never stored.
            $table->string('prefix', 16);
            $table->char('token_hash', 64)->unique();
            $table->jsonb('scopes');
            $table->jsonb('ip_allowlist')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'revoked_at']);
        });

        Schema::create('connect_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            // "grant:<uuid>" or "key:<id>": a key is unique per credential, never shared between them.
            $table->string('credential', 60);
            $table->string('idempotency_key', 255);
            $table->char('request_hash', 64);
            $table->string('status', 16);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->jsonb('response_headers')->nullable();
            $table->timestampTz('expires_at');
            $table->timestamps();

            $table->unique(['credential', 'idempotency_key']);
            $table->index('expires_at');
        });
    }
};
