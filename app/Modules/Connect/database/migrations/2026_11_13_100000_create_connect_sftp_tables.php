<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * SFTP for SIE files in NONOX Connect (ADR-032, "SFTP"):
 *
 * - connect_sftp_accounts: one SFTP drop per company, with a generated user
 *   name that says nothing about the company, the person responsible for
 *   what arrives, and an optional allowlist of addresses.
 * - connect_sftp_keys: the SSH public keys that may log in (ed25519 or RSA
 *   of at least 3072 bits). Only public keys are stored; a removed key is
 *   kept with revoked_at for the history.
 * - connect_sftp_uploads: the upload log. One row per file the SFTP server
 *   reported, deduplicated on event_key, never the file's contents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connect_sftp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('teams')->cascadeOnDelete();
            $table->string('username', 32)->unique();
            $table->boolean('is_enabled')->default(false);
            // The person uploads are read in as: they must still hold connect:manage and import:sie.
            $table->foreignId('responsible_user_id')->constrained('users');
            $table->jsonb('allowed_cidrs')->default('[]');
            $table->timestampTz('enabled_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestampTz('last_upload_at')->nullable();
            $table->timestampTz('last_export_at')->nullable();
            $table->timestamps();
        });

        Schema::create('connect_sftp_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('connect_sftp_accounts')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('algorithm', 32);
            $table->unsignedSmallInteger('bits');
            // "type base64", without the comment.
            $table->text('public_key');
            // OpenSSH's SHA256 fingerprint: "SHA256:" and 43 base64 characters.
            $table->string('fingerprint', 60);
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['account_id', 'revoked_at']);
        });

        // A key can be active only once per account.
        DB::statement('CREATE UNIQUE INDEX connect_sftp_keys_active_fingerprint ON connect_sftp_keys (account_id, fingerprint) WHERE revoked_at IS NULL');

        Schema::create('connect_sftp_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('connect_sftp_accounts')->cascadeOnDelete();
            // SHA-256 of user name, path, time and session of the server's notice: the same notice twice is one upload.
            $table->char('event_key', 64)->unique();
            $table->string('file_name', 255);
            $table->unsignedBigInteger('size');
            $table->string('ip', 45)->nullable();
            $table->string('status', 16);
            $table->string('reason', 255)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->foreignId('sie_import_id')->nullable()->constrained('sie_imports');
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });
    }
};
