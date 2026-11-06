<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Skatteverket's customer events about the company (kundhändelser, ADR-054
 * stage 4), kept so that everyone in the company who may see them can read
 * them in full after the person who fetched them has left the page, and so
 * Översikt can count those that wait for the company. Only what Skatteverket
 * shows the company: its id, type, producer, time, heading, description,
 * language, the two flags, a due date and links to skatteverket.se. Never
 * metadata.anvandare (the personnummer of the person who fetched) or the
 * roles. The heading and description are Skatteverket's free text and can
 * hold personal data. Pruned two years after the event, when Skatteverket
 * also removes it (Tjänstebeskrivning 2.2.3). Personal data: the id of the
 * user who marked an event handled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skatteverket_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('event_id');
            $table->string('type');
            $table->string('producer');
            $table->timestampTz('occurred_at')->nullable();
            $table->text('title');
            $table->text('description');
            $table->string('language', 16);
            $table->boolean('requires_action');
            $table->boolean('case_closed');
            $table->date('due_date')->nullable();
            $table->jsonb('links')->nullable();
            $table->timestampTz('fetched_at');
            $table->timestampTz('handled_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'event_id']);
            $table->index(['company_id', 'requires_action', 'handled_at']);
        });
    }
};
