<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archive packages (AGENTS.md §10): one ZIP per company and fiscal year with
 * everything the year's räkenskapsinformation consists of, readable without
 * this system, and a SHA-256 for every file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->string('status', 20)->default('QUEUED');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->bigInteger('size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->integer('file_count')->nullable();
            $table->jsonb('contents')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'fiscal_year_id']);
        });
    }
};
