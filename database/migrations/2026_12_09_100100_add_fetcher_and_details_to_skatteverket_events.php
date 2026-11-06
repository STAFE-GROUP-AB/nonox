<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Skatteverket's customer events belong to the person who fetched them
 * (ADR-054 stage 4, after review): Allmänna villkor Kundhändelser 2 and 5.1
 * name the end user as the representative who makes the request, and the
 * content can be secret (7.3). So each row has its fetcher, and an event two
 * people fetched is two rows. Also kept, as content for that person: the
 * extended fields kopplingsId, skatteslag, kundamne, korrespondensReferens
 * and aviseringsmeddelande. A row whose fetcher is deleted loses the user id
 * and is pruned by privacy:prune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skatteverket_events', function (Blueprint $table): void {
            $table->foreignId('fetched_by')->nullable()->after('company_id')->constrained('users')->nullOnDelete();
            $table->string('kopplings_id')->nullable();
            $table->string('skatteslag')->nullable();
            $table->jsonb('kundamne')->nullable();
            $table->string('korrespondens_referens')->nullable();
            $table->text('notice')->nullable();

            $table->dropUnique(['company_id', 'event_id']);
            $table->dropIndex(['company_id', 'requires_action', 'handled_at']);
            $table->unique(['company_id', 'fetched_by', 'event_id']);
            $table->index(['company_id', 'fetched_by', 'requires_action', 'handled_at']);
            $table->index(['company_id', 'fetched_by', 'kopplings_id']);
        });
    }
};
