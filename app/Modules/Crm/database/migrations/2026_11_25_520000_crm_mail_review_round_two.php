<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM stage 5, second review round (ADR-048):
 *
 * - clicks are counted per link only: a click link carries no recipient,
 *   so the per-recipient click marks go (LEK 9 kap. 28 §, ePrivacy 5(3));
 * - a dated consent to e-mail marketing on the person, apart from the
 *   general legal basis, and a dated mark for the customer exception
 *   (marknadsföringslagen 19 § second paragraph);
 * - whether the newsletter box was ticked on a form submission;
 * - a recipient stub keeps only a keyed hash of the address after 90 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('crm_mailing_clicks');

        Schema::table('crm_mailing_recipients', function (Blueprint $table) {
            $table->dropColumn('clicked_at');
            // The suppression list's HMAC of the address, kept instead of the address and the person after 90 days.
            $table->char('email_hash', 64)->nullable();
            // A spam complaint from the mailbox provider, counted once per recipient.
            $table->timestampTz('complained_at')->nullable();
        });

        Schema::table('crm_mailing_links', function (Blueprint $table) {
            $table->dropColumn('unique_clicks');
        });

        Schema::table('crm_mailings', function (Blueprint $table) {
            $table->dropColumn('unique_clicks');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('complaint_count')->default(0);
        });

        Schema::table('crm_people', function (Blueprint $table) {
            $table->timestampTz('marketing_consent_at')->nullable();
            $table->date('customer_exception_at')->nullable();
        });

        Schema::table('crm_form_submissions', function (Blueprint $table) {
            $table->boolean('marketing_consent')->default(false);
        });
    }
};
