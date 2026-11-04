<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Peppol e-invoicing (ADR-050): each company's own Peppol participant and
 * its account with the installation's access-point provider, the documents
 * sent, and how customers want their invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('peppol_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('teams')->cascadeOnDelete();
            // "0007:5561234567": one company per identifier on the installation, so an inbound document has one receiver.
            $table->string('identifier', 60)->unique();
            // The company's earlier Peppol IDs: documents still addressed to them are kept, never refused.
            $table->jsonb('previous_identifiers')->default('[]');
            $table->boolean('receive_enabled')->default(false);
            // The access-point provider's own id for the company's account, set by the operator (peppol:link).
            $table->string('provider', 30)->nullable();
            $table->string('provider_account', 120)->nullable();
            // The provider's webhook subscription for the account and its signing secret (encrypted cast).
            $table->string('webhook_id', 120)->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('registration_status', 20)->default('NOT_REGISTERED');
            $table->timestampTz('registered_at')->nullable();
            $table->foreignId('consent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('consent_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestampTz('last_polled_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_account']);
        });

        Schema::create('peppol_transmissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->string('document_type', 12);
            $table->string('receiver', 60);
            $table->string('status', 12);
            $table->string('driver', 30);
            $table->string('provider_reference', 120)->nullable();
            // The document exactly as handed to the access point, on the private disk; never removed (BFL 7 kap.).
            $table->string('xml_path', 500);
            $table->char('xml_sha256', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            // The access point refused the document (as opposed to not answering): sending it again unchanged cannot help.
            $table->boolean('refused')->default(false);
            $table->string('last_error', 500)->nullable();
            $table->foreignId('queued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('queued_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'invoice_id']);
            $table->index(['company_id', 'status']);
            $table->index(['driver', 'provider_reference']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('peppol_id', 60)->nullable();
            // EMAIL or PEPPOL: how the customer wants its invoices.
            $table->string('invoice_delivery', 10)->default('EMAIL');
        });
    }
};
