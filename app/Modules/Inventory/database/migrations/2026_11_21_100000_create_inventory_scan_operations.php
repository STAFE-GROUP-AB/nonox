<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The scanning app (ADR-040): every operation a phone sends carries a UUID
 * made on the phone. The first time it arrives it is run and its answer is
 * kept here; a resend (the phone lost the answer, or was offline) gets the
 * same answer and nothing is posted twice. A short pick keeps its reason
 * on the pick list line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_scan_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->uuid('uuid');
            // receive, pick, move, count, count_start
            $table->string('kind', 20);
            // PENDING while it runs, then OK, REJECTED or NOT_FOUND.
            $table->string('status', 12);
            $table->jsonb('result')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'uuid']);
            $table->index(['company_id', 'user_id', 'created_at']);
        });

        Schema::table('pick_list_lines', function (Blueprint $table) {
            $table->string('short_reason', 40)->nullable();
        });
    }
};
