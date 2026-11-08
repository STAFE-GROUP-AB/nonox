<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The customer portal (ADR-075). Portal contacts are never NONOX users:
 *
 * - portal_identities: one per e-mail address, global like users, holding
 *   no company data (an e-mail and a name).
 * - customer_portal_contacts: an identity's access to one customer of one
 *   company (INVITED, ACTIVE, DISABLED).
 * - customer_portal_invitations: a 256-bit token stored as SHA-256, 14 days,
 *   one use; opening it also needs a code sent to the invited address.
 * - customer_portal_settings: the company's random portal key (16
 *   characters, used in addresses and to pick the brand before sign-in),
 *   the "Drivs med NONOX" footer, the invoice history and its privacy text.
 * - customer_portal_threads / _messages / _attachments: questions and
 *   disputes with the company, kept (unlike the chat).
 * - customer_portal_events: append-only, ids only; IP and browser only on
 *   SIGNED_IN.
 * - customer_portal_payment_notes: "Jag har betalat", which books nothing.
 * - crm_signing_requests gains channel (LINK or PORTAL) and the portal
 *   contact who accepted a quote while signed in (ADR-075 §7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_identities', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name', 120);
            $table->timestampTz('sessions_valid_from')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_portal_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('portal_identity_id')->constrained('portal_identities')->cascadeOnDelete();
            $table->foreignId('crm_person_id')->nullable()->constrained('crm_people')->nullOnDelete();
            $table->string('status', 10)->default('INVITED');
            $table->boolean('can_accept_quotes')->default(true);
            $table->boolean('can_order')->default(false);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('inactivity_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'customer_id', 'portal_identity_id']);
            $table->index(['portal_identity_id', 'status']);
        });

        Schema::create('customer_portal_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('customer_portal_contacts')->cascadeOnDelete();
            $table->char('token_sha256', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at');
        });

        Schema::create('customer_portal_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('teams')->cascadeOnDelete();
            $table->char('portal_key', 16)->unique();
            $table->boolean('show_powered_by')->default(true);
            $table->unsignedSmallInteger('history_months')->default(24);
            $table->text('privacy_notice')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_portal_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('customer_portal_contacts')->nullOnDelete();
            // INVOICE or QUOTE, with the document's id; or none.
            $table->string('subject_type', 10)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('subject', 200);
            // QUESTION or DISPUTE; OPEN or CLOSED.
            $table->string('kind', 10)->default('QUESTION');
            $table->string('status', 10)->default('OPEN');
            $table->boolean('keep')->default(false);
            $table->boolean('answered')->default(false);
            $table->timestampTz('last_message_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'customer_id', 'status']);
            $table->index(['company_id', 'subject_type', 'subject_id']);
        });

        Schema::create('customer_portal_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('customer_portal_threads')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('customer_portal_contacts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // CONTACT or USER: who wrote it, kept when the author is removed ("Borttagen kontakt").
            $table->string('author_kind', 10);
            $table->text('body');
            $table->timestampTz('created_at');

            $table->index(['company_id', 'thread_id']);
        });

        Schema::create('customer_portal_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('customer_portal_messages')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name', 200);
            $table->string('mime', 60);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->timestampTz('created_at');

            $table->index(['company_id', 'message_id']);
        });

        Schema::create('customer_portal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('customer_portal_contacts')->nullOnDelete();
            $table->string('event', 20);
            $table->string('subject_type', 10)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestampTz('created_at');

            $table->index(['company_id', 'subject_type', 'subject_id']);
            $table->index(['company_id', 'customer_id', 'created_at']);
        });

        Schema::create('customer_portal_payment_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->uuid('invoice_id');
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('customer_portal_contacts')->nullOnDelete();
            $table->date('paid_on');
            $table->timestampTz('created_at');

            $table->index(['company_id', 'invoice_id']);
        });

        Schema::table('crm_signing_requests', function (Blueprint $table) {
            // LINK: signed at /signera/{token}; PORTAL: accepted by a signed-in portal contact.
            $table->string('channel', 10)->default('LINK');
            $table->foreignId('portal_contact_id')->nullable()->constrained('customer_portal_contacts')->nullOnDelete();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION customer_portal_events_append_only() RETURNS trigger AS $$
            BEGIN
                -- Only a removed contact's id may be cleared (ON DELETE SET NULL); nothing else changes.
                IF NEW.id = OLD.id AND NEW.company_id = OLD.company_id AND NEW.customer_id = OLD.customer_id
                    AND NEW.contact_id IS NULL
                    AND NEW.event = OLD.event
                    AND NEW.subject_type IS NOT DISTINCT FROM OLD.subject_type
                    AND NEW.subject_id IS NOT DISTINCT FROM OLD.subject_id
                    AND NEW.ip IS NOT DISTINCT FROM OLD.ip
                    AND NEW.user_agent IS NOT DISTINCT FROM OLD.user_agent
                    AND NEW.created_at = OLD.created_at THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'Customer portal events are append-only (ADR-075).';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER customer_portal_events_append_only BEFORE UPDATE ON customer_portal_events
                FOR EACH ROW EXECUTE FUNCTION customer_portal_events_append_only();

            CREATE OR REPLACE FUNCTION crm_signing_requests_protect_channel() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('SIGNED', 'DECLINED') AND (
                    NEW.channel IS DISTINCT FROM OLD.channel
                    OR (NEW.portal_contact_id IS DISTINCT FROM OLD.portal_contact_id AND NEW.portal_contact_id IS NOT NULL)
                ) THEN
                    RAISE EXCEPTION 'A signed or declined signing request cannot be changed (ADR-046).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER crm_signing_requests_protect_channel BEFORE UPDATE ON crm_signing_requests
                FOR EACH ROW EXECUTE FUNCTION crm_signing_requests_protect_channel();
        SQL);
    }
};
