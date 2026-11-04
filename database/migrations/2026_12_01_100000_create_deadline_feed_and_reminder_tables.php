<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mina datum (ADR-051, stage 2): each user's secret calendar link for a
 * company's deadlines (only the SHA-256 of the token is kept), and the
 * reminders already sent, so none is sent twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deadline_calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'revoked_at']);
        });

        Schema::create('deadline_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('deadline_key', 100);
            $table->date('deadline_date');
            $table->unsignedSmallInteger('days_before');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['company_id', 'user_id', 'deadline_key', 'deadline_date', 'days_before'], 'deadline_reminders_once');
        });
    }
};
