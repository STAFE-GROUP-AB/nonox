<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arbetsgivardeklarationer per company and payment month (PAYROLL_MODULE.md
 * B.13). Each file generated for a period is kept with its checksum; a
 * correction is a new row for the same period. The individual amounts are
 * kept per employee id, never with personal numbers, so a later correction
 * can remove what is no longer there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agi_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->char('period', 7);
            $table->unsignedInteger('sequence');
            $table->string('status', 20)->default('GENERATED');
            $table->string('xml_path');
            $table->char('sha256', 64);
            $table->bigInteger('tax_ore');
            $table->bigInteger('contributions_ore');
            $table->jsonb('individuals');
            $table->unsignedInteger('removals')->default(0);
            $table->string('receipt_reference', 100)->nullable();
            $table->foreignId('generated_by')->constrained('users');
            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'period', 'sequence']);
        });
    }
};
