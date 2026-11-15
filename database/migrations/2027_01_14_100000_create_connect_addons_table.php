<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * NONOX Connect (ADR-064 §2): the add-on switch per company and connection.
 * A connection is used only while its row says on, and it is never billed
 * before someone has turned it on and seen the price (ADR-064 §3). The first
 * connection with a switch is Stripe (ADR-080 §7.8, addendum step 2). Ids and
 * enums only; who changed it and when is also in the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connect_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('connection', 40);
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'connection']);
        });
    }
};
