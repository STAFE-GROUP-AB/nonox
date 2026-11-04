<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM stage 3 (ADR-046): quote templates, the agreement register and
 * signing with a signature pad.
 *
 * - crm_quote_templates / crm_quote_template_rows: reusable quotes. An
 *   article row takes the article's price, unit and VAT rate when a quote is
 *   made from the template; a free row keeps its own.
 * - crm_agreements: an account's agreements with term, notice period and
 *   renewal; reminded_for_end_date makes the daily reminder idempotent.
 * - crm_signing_requests: one link sent for signing. Only the SHA-256 of the
 *   token is kept. document_sha256 binds the signature to the exact PDF
 *   shown; once signed or declined the evidence never changes (trigger).
 * - crm_signing_events: the audit trail of a link (sent, opened, signed,
 *   declined, expired, revoked); rows are never updated (trigger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_quote_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('intro_text')->nullable();
            $table->text('terms_text')->nullable();
            $table->unsignedSmallInteger('validity_days')->default(30);
            $table->timestampTz('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'archived_at']);
        });

        Schema::create('crm_quote_template_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('crm_quote_templates')->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->string('description')->nullable();
            $table->decimal('quantity', 15, 3)->default(1);
            $table->string('unit', 20)->nullable();
            // Only for a free row; an article row takes the article's price when used.
            $table->bigInteger('unit_price_ore')->default(0);
            $table->unsignedSmallInteger('vat_rate')->default(2500);
            $table->unsignedSmallInteger('discount_bp')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'template_id']);
        });

        Schema::create('crm_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('crm_accounts')->cascadeOnDelete();
            $table->string('title');
            $table->string('kind', 20)->default('SERVICE');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('notice_months')->default(0);
            $table->boolean('auto_renew')->default(false);
            $table->unsignedSmallInteger('renewal_months')->nullable();
            $table->bigInteger('value_ore')->default(0);
            $table->string('value_period', 10)->default('MONTH');
            $table->uuid('recurring_invoice_id')->nullable();
            $table->foreign('recurring_invoice_id')->references('id')->on('recurring_invoices')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body_text')->nullable();
            $table->text('notes')->nullable();
            // An uploaded PDF of the agreement (private disk); without one a PDF is generated from the fields and the text.
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();
            $table->char('document_sha256', 64)->nullable();
            $table->date('terminated_on')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->date('reminded_for_end_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'account_id']);
            $table->index(['company_id', 'end_date']);
        });

        DB::statement('ALTER TABLE crm_agreements ADD CONSTRAINT crm_agreements_value_check CHECK (value_ore >= 0)');
        DB::statement('ALTER TABLE crm_agreements ADD CONSTRAINT crm_agreements_dates_check CHECK (end_date IS NULL OR end_date >= start_date)');

        Schema::create('crm_signing_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            // QUOTE or AGREEMENT.
            $table->string('document_type', 10);
            $table->uuid('sales_document_id')->nullable();
            $table->foreign('sales_document_id')->references('id')->on('sales_documents')->cascadeOnDelete();
            $table->foreignId('agreement_id')->nullable()->constrained('crm_agreements')->cascadeOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained('crm_deals')->nullOnDelete();
            // SHA-256 of the 256-bit token; the token itself is only in the e-mail.
            $table->char('token_hash', 64)->unique();
            $table->string('email');
            // PENDING, SIGNED, DECLINED or REVOKED; an unsigned link past expires_at is expired.
            $table->string('status', 10)->default('PENDING');
            $table->timestampTz('expires_at');
            $table->string('document_path');
            $table->string('document_name');
            $table->char('document_sha256', 64);
            // A fingerprint of the quote's contents when sent: a changed quote cannot be signed.
            $table->char('content_hash', 64)->nullable();
            $table->boolean('create_invoice')->default(false);
            $table->timestampTz('opened_at')->nullable();
            $table->string('signer_name', 120)->nullable();
            $table->string('signer_title', 120)->nullable();
            // DRAWN or TYPED.
            $table->string('signature_method', 10)->nullable();
            // The drawn signature as a base64 PNG, encrypted (Laravel's encrypted cast).
            $table->text('signature_image')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->string('signer_ip', 45)->nullable();
            $table->string('signer_user_agent', 500)->nullable();
            $table->string('signed_document_path')->nullable();
            $table->char('signed_document_sha256', 64)->nullable();
            $table->timestampTz('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('order_id')->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->timestampTz('followed_up_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'sales_document_id']);
            $table->index(['company_id', 'agreement_id']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('crm_signing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('signing_request_id')->constrained('crm_signing_requests')->cascadeOnDelete();
            // SENT, OPENED, SIGNED, DECLINED, EXPIRED, REVOKED.
            $table->string('event', 10);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Kept for SIGNED and DECLINED only: the signer's own act.
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestampTz('created_at');

            $table->index(['company_id', 'signing_request_id']);
        });

        // The evidence of a signed or declined link never changes; the follow-up columns (order, invoice, followed_up_at) may be set once.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION crm_signing_requests_protect() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('SIGNED', 'DECLINED') THEN
                    IF NEW.status IS DISTINCT FROM OLD.status
                        OR NEW.token_hash IS DISTINCT FROM OLD.token_hash
                        OR NEW.email IS DISTINCT FROM OLD.email
                        OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                        OR NEW.document_path IS DISTINCT FROM OLD.document_path
                        OR NEW.document_sha256 IS DISTINCT FROM OLD.document_sha256
                        OR NEW.content_hash IS DISTINCT FROM OLD.content_hash
                        OR NEW.signer_name IS DISTINCT FROM OLD.signer_name
                        OR NEW.signer_title IS DISTINCT FROM OLD.signer_title
                        OR NEW.signature_method IS DISTINCT FROM OLD.signature_method
                        OR NEW.signature_image IS DISTINCT FROM OLD.signature_image
                        OR NEW.signed_at IS DISTINCT FROM OLD.signed_at
                        OR NEW.signer_ip IS DISTINCT FROM OLD.signer_ip
                        OR NEW.signer_user_agent IS DISTINCT FROM OLD.signer_user_agent
                        OR NEW.declined_at IS DISTINCT FROM OLD.declined_at
                        OR NEW.decline_reason IS DISTINCT FROM OLD.decline_reason
                        OR (OLD.signed_document_sha256 IS NOT NULL AND NEW.signed_document_sha256 IS DISTINCT FROM OLD.signed_document_sha256)
                        OR (OLD.signed_document_path IS NOT NULL AND NEW.signed_document_path IS DISTINCT FROM OLD.signed_document_path)
                        OR (OLD.order_id IS NOT NULL AND NEW.order_id IS DISTINCT FROM OLD.order_id)
                        OR (OLD.invoice_id IS NOT NULL AND NEW.invoice_id IS DISTINCT FROM OLD.invoice_id)
                    THEN
                        RAISE EXCEPTION 'A signed or declined signing request cannot be changed (ADR-046).';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER crm_signing_requests_protect BEFORE UPDATE ON crm_signing_requests
                FOR EACH ROW EXECUTE FUNCTION crm_signing_requests_protect();

            CREATE OR REPLACE FUNCTION crm_signing_events_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Signing events are append-only (ADR-046).';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER crm_signing_events_append_only BEFORE UPDATE ON crm_signing_events
                FOR EACH ROW EXECUTE FUNCTION crm_signing_events_append_only();
        SQL);
    }
};
