<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Skatteverket's partner APIs (ADR-054).
 *
 * skatteverket_tokens: a person's sign-in with Skatteverket. It belongs to
 * the user, not to a company: Skatteverket checks on every call whom the
 * person may represent. The tokens are encrypted and live at most an hour
 * (65 minutes with the refresh token); expired rows are pruned.
 *
 * vat_submissions: a VAT return sent to Skatteverket as a draft, with
 * Skatteverket's check result, the signing link and, once fetched, the
 * receipt and the decided amounts. No personal data: who signed at
 * Skatteverket is not stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skatteverket_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->string('scope', 255);
            $table->timestampTz('expires_at');
            $table->timestampTz('refresh_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vat_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20);
            $table->jsonb('boxes');
            $table->string('check_status', 10)->nullable();
            $table->jsonb('check_results')->nullable();
            $table->boolean('locked')->default(false);
            $table->text('signing_link')->nullable();
            $table->string('receipt_number', 64)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->jsonb('decided_boxes')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'period_end']);
        });
    }
};
