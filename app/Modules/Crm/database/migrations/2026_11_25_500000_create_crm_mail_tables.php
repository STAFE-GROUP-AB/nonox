<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM stage 5 (ADR-048): e-mail logged through the company's +crm address,
 * e-mail templates, segments, mailings with click counts (never opens) and
 * unsubscribe, and web forms that create leads.
 *
 * Personal data: a logged e-mail's body and the addresses in it are
 * encrypted at rest (Laravel's encrypted cast). A mailing recipient keeps
 * the address only until 90 days after the send (crm:prune-mail), and the
 * row itself (the unsubscribe token) for 13 months. A form submission keeps
 * the consent text exactly as it was shown, as proof of consent (art. 7.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_people', function (Blueprint $table) {
            // Unsubscribed from mailings (art. 21(3), direct marketing only); other contact is still allowed.
            $table->timestampTz('marketing_opt_out_at')->nullable();
            // A mailing to the address bounced permanently; cleared when the address changes.
            $table->timestampTz('email_bounced_at')->nullable();
        });

        Schema::create('crm_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            // IN (from the other party) or OUT (from someone at the company).
            $table->string('direction', 3);
            // LOGGED, UNMATCHED, HELD (outbound to someone marked Kontakta inte) or RESTRICTED (stored only, art. 18).
            $table->string('status', 12);
            $table->string('channel', 10);
            $table->string('subject', 255);
            // Sanitised plain text, at most 500 characters: what the timeline shows.
            $table->text('excerpt')->nullable();
            // Encrypted: the full plain-text body and the addresses.
            $table->text('body')->nullable();
            $table->text('participants');
            $table->text('attachment_names')->nullable();
            $table->string('message_id', 998)->nullable();
            $table->char('sha256', 64);
            $table->foreignId('forwarded_by')->nullable()->constrained('users')->nullOnDelete();
            // The person it was matched to first: erasing the person removes the e-mail with them.
            $table->foreignId('person_id')->nullable()->constrained('crm_people')->cascadeOnDelete();
            $table->timestampTz('sent_at');
            $table->timestamps();

            $table->unique(['company_id', 'sha256']);
            $table->index(['company_id', 'status', 'created_at']);
        });

        Schema::table('crm_activities', function (Blueprint $table) {
            $table->foreignId('email_id')->nullable()->constrained('crm_emails')->nullOnDelete();
        });

        Schema::create('crm_email_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('subject', 200);
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'name']);
        });

        Schema::create('crm_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            $table->jsonb('filters');
            $table->timestamps();

            $table->index(['company_id', 'name']);
        });

        Schema::create('crm_mailings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('segment_id')->nullable()->constrained('crm_segments')->nullOnDelete();
            $table->string('name', 100);
            $table->string('subject', 200);
            // Markdown, rendered to HTML with the company's look at send time.
            $table->text('body');
            // The customer exception (marknadsföringslagen 19 § second paragraph) was confirmed for this mailing.
            $table->boolean('customer_exception')->default(false);
            // DRAFT, SCHEDULED, SENDING, SENT or CANCELLED.
            $table->string('status', 10)->default('DRAFT');
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            // Counts kept on the mailing, so the statistics outlive the pruned recipient rows.
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('bounced_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('unique_clicks')->default(0);
            $table->unsignedInteger('unsubscribed_count')->default(0);
            $table->jsonb('excluded')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'scheduled_at']);
        });

        Schema::create('crm_mailing_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('mailing_id')->constrained('crm_mailings')->cascadeOnDelete();
            $table->string('url', 2000);
            $table->unsignedSmallInteger('position');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('unique_clicks')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'mailing_id']);
        });

        Schema::create('crm_mailing_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('mailing_id')->constrained('crm_mailings')->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('crm_people')->nullOnDelete();
            // Null once pruned (90 days after the send).
            $table->string('email', 255)->nullable();
            // SHA-256 of the random token in the recipient's links; the token itself is never stored.
            $table->char('token_hash', 64)->unique();
            // QUEUED, SENT, FAILED, BOUNCED, SKIPPED or CANCELLED.
            $table->string('status', 10)->default('QUEUED');
            $table->string('skip_reason', 30)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('clicked_at')->nullable();
            $table->timestampTz('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'mailing_id', 'status']);
        });

        Schema::create('crm_mailing_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('crm_mailing_recipients')->cascadeOnDelete();
            $table->foreignId('link_id')->constrained('crm_mailing_links')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['recipient_id', 'link_id']);
        });

        Schema::create('crm_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            // The public address /formular/{token}: random, never the id.
            $table->string('token', 40)->unique();
            // Which optional fields are shown and which are required.
            $table->jsonb('fields');
            $table->text('consent_text');
            $table->unsignedInteger('consent_version')->default(1);
            $table->string('privacy_url', 500);
            $table->text('thank_you')->nullable();
            // The sites that may show the form in an iframe (https origins); empty: only the hosted page.
            $table->jsonb('embed_origins')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tags', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('submission_count')->default(0);
            $table->timestamps();
        });

        Schema::create('crm_form_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('form_id')->constrained('crm_forms')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('crm_accounts')->nullOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('crm_people')->nullOnDelete();
            // Proof of consent (GDPR art. 7.1): the text exactly as shown, and its version.
            $table->text('consent_text');
            $table->unsignedInteger('consent_version');
            $table->string('privacy_url', 500);
            $table->timestampTz('consented_at');
            $table->timestamps();

            $table->index(['company_id', 'form_id']);
        });
    }
};
