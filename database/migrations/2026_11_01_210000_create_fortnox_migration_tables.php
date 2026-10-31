<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moving from Fortnox through their API (AGENTS.md §14): the company's
 * connection, with the tokens encrypted and removed when the move is
 * complete, and every file brought over as an attachment, so a file is
 * never fetched twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fortnox_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('teams')->cascadeOnDelete();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('scope', 1000)->nullable();
            $table->string('fortnox_company_name')->nullable();
            $table->string('fortnox_org_number', 20)->nullable();
            $table->jsonb('progress')->default('{}');
            $table->foreignId('connected_by')->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fortnox_imported_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('file_id', 100);
            $table->unsignedInteger('fortnox_year');
            $table->string('voucher_series', 10);
            $table->unsignedInteger('voucher_number');
            $table->string('name')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->uuid('voucher_id')->nullable();
            $table->string('message')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'file_id', 'fortnox_year', 'voucher_series', 'voucher_number'], 'fortnox_files_unique');
            $table->index(['company_id', 'status']);
        });
    }
};
