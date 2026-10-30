<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How an accounting firm works with a client company: the consultant
 * responsible for it and the firm's own note, which the client does not see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('teams')->cascadeOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('note_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('note_updated_at')->nullable();
            $table->timestamps();
        });
    }
};
