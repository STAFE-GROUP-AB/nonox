<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Senast öppnade" in the spotlight (ADR-026): the records a user opened
 * last, per company. The title is a snapshot taken when the record was
 * opened, so listing them costs one query. At most 50 rows are kept per
 * user and company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recent_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('record_id', 64);
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('url', 500);
            $table->string('icon', 30);
            $table->timestamp('opened_at');
            $table->timestamps();

            $table->unique(['company_id', 'user_id', 'type', 'record_id']);
            $table->index(['company_id', 'user_id', 'opened_at']);
        });
    }
};
