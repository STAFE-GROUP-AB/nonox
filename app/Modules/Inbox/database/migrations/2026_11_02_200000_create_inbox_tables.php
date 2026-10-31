<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * NONOX Inbox (ADR-023): one address per company, its allowlist of senders,
 * received mail with its attachments, learned templates, and mail to the
 * shared address that matches more than one company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('teams')->cascadeOnDelete();
            $table->string('slug', 80)->unique();
            $table->timestampTz('slug_changed_at')->nullable();
            $table->unsignedBigInteger('imap_last_uid')->default(0);
            $table->timestampTz('imap_last_fetched_at')->nullable();
            $table->string('imap_last_error', 500)->nullable();
            $table->timestampTz('notified_at')->nullable();
            $table->date('summary_sent_on')->nullable();
            $table->timestamps();
        });

        Schema::create('inbox_allowed_senders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            // "name@example.se" for one address, "@example.se" for a whole domain; always lower case.
            $table->string('pattern', 255);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'pattern']);
            $table->index('pattern');
        });

        Schema::create('inbox_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('sender', 255);
            $table->string('kind', 30);
            $table->string('format', 20);
            $table->char('fingerprint', 64);
            $table->string('name', 255);
            $table->jsonb('mapping');
            $table->boolean('auto_book')->default(false);
            $table->foreignId('auto_book_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('uses')->default(0);
            $table->timestampTz('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'sender', 'fingerprint']);
        });

        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('channel', 10);
            $table->string('recipient', 255);
            $table->string('alias', 20)->nullable();
            $table->string('from_email', 255);
            $table->string('from_name', 255)->nullable();
            $table->string('subject', 998)->nullable();
            $table->string('message_id', 998)->nullable();
            $table->timestampTz('received_at');
            $table->string('eml_path', 500);
            $table->char('eml_sha256', 64);
            $table->unsignedInteger('eml_size');
            $table->text('body_text')->nullable();
            $table->jsonb('auth');
            $table->string('status', 20);
            $table->jsonb('quarantine_reasons');
            $table->jsonb('warnings');
            $table->string('document_type', 30)->nullable();
            $table->string('interpreted_by', 20)->nullable();
            $table->jsonb('proposal')->nullable();
            $table->foreignId('template_id')->nullable()->constrained('inbox_templates')->nullOnDelete();
            $table->jsonb('voucher_ids');
            $table->foreignUuid('supplier_invoice_id')->nullable()->constrained('supplier_invoices')->nullOnDelete();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('released_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('handled_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'eml_sha256']);
            $table->index(['company_id', 'status', 'received_at']);
            $table->index(['company_id', 'from_email']);
        });

        Schema::create('inbox_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('inbox_message_id')->constrained('inbox_messages')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('filename', 255);
            $table->string('mime', 255);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->string('path', 500);
            $table->string('blocked_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'sha256']);
        });

        // Not company-scoped: it belongs to no company until a member of one of the candidates claims it.
        Schema::create('inbox_unassigned_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('recipient', 255);
            $table->string('from_email', 255);
            $table->string('from_name', 255)->nullable();
            $table->string('subject', 998)->nullable();
            $table->timestampTz('received_at');
            $table->string('eml_path', 500);
            $table->char('eml_sha256', 64)->unique();
            $table->unsignedInteger('eml_size');
            $table->jsonb('transport');
            $table->jsonb('candidate_company_ids');
            $table->foreignId('claimed_company_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestamps();
        });
    }
};
