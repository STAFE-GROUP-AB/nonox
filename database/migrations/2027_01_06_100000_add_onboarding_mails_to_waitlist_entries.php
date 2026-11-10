<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The follow-up mails of the onboarding (ADR-038 addendum): when each of the
 * two was sent, so that each goes out at most once, and when the person said
 * "Inga fler påminnelser". Timestamps only, no personal data; they go with
 * the entry when it is pruned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->timestamp('login_reminder_sent_at')->nullable()->after('approved_user_id');
            $table->timestamp('help_reminder_sent_at')->nullable()->after('login_reminder_sent_at');
            $table->timestamp('reminders_opted_out_at')->nullable()->after('help_reminder_sent_at');
        });
    }
};
