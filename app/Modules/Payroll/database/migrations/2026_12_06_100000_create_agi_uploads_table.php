<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * An arbetsgivardeklaration file read into the company's own space at
 * Skatteverket through its API (ADR-054, stage 2). Only ids, statuses,
 * counts and dates are kept: Skatteverket's upload id, the check status and
 * the number of errors and warnings, the review link to Mina sidor, and the
 * period's state and latest receipt id. Never a personnummer, a name or who
 * signed (signeradAv): Skatteverket's error texts are shown when fetched and
 * not stored. Personal data: the uploading user's id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agi_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('agi_submission_id')->constrained('agi_submissions')->cascadeOnDelete();
            $table->char('period', 7);
            $table->unsignedBigInteger('upload_id');
            $table->char('sha256', 64);
            $table->string('status', 20);
            $table->string('report_status', 20)->nullable();
            $table->unsignedInteger('main_count')->nullable();
            $table->unsignedInteger('individual_count')->nullable();
            $table->unsignedInteger('error_count')->nullable();
            $table->unsignedInteger('warning_count')->nullable();
            $table->string('review_state', 30)->nullable();
            $table->text('review_link')->nullable();
            $table->timestampTz('review_created_at')->nullable();
            $table->string('period_state', 30)->nullable();
            $table->boolean('period_signed')->nullable();
            $table->unsignedInteger('receipt_count')->nullable();
            $table->string('latest_receipt_id', 64)->nullable();
            $table->timestampTz('latest_signed_at')->nullable();
            $table->timestampTz('period_fetched_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('uploaded_at');
            $table->timestampTz('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'upload_id']);
            $table->index(['company_id', 'period']);
        });
    }
};
