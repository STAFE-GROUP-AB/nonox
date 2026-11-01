<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NONOX AI fills in the agreement setup (ADR-033 §6): documents the company
 * uploads (a hängavtal, a membership confirmation, an employment contract,
 * a payslip), kept on the private disk, and the proposals the AI read from
 * them, each with the quote and page it read it from. A proposal changes
 * nothing until a person with agreements:manage accepts it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreement_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees');
            $table->string('document_type', 30);
            // A file name can carry a person's name: encrypted.
            $table->text('original_name');
            $table->string('file_path', 500);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'sha256']);
        });

        Schema::create('agreement_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('agreement_document_id')->constrained('agreement_documents')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees');
            $table->foreignId('employment_id')->nullable()->constrained('employments');
            $table->string('agent', 30);
            $table->string('field', 40);
            $table->jsonb('value');
            // The sentence the AI read the value from: personal data, encrypted.
            $table->text('quote')->nullable();
            $table->unsignedSmallInteger('page')->nullable();
            $table->boolean('quote_found')->default(false);
            $table->string('status', 20)->default('PENDING');
            $table->jsonb('decided_value')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }
};
