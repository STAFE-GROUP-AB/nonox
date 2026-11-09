<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The customer portal (ADR-075):
 *
 * - login_codes.audience: WEB (NONOX's own sign-in) or PORTAL (a customer
 *   portal contact). A code issued for one is never accepted by the other.
 * - invoice_documents: the invoice PDF exactly as it was issued (decision
 *   O6), stored once at issue with its SHA-256, so the portal, the e-mail
 *   and the archive package show the same bytes. Never changed afterwards
 *   (trigger); an invoice issued before this has none and is re-rendered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_codes', function (Blueprint $table) {
            $table->string('audience', 10)->default('WEB');
            $table->index(['email', 'audience']);
        });

        Schema::create('invoice_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->uuid('invoice_id')->unique();
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->string('path');
            $table->char('sha256', 64);
            $table->unsignedInteger('size');
            $table->timestampTz('created_at');

            $table->index(['company_id', 'invoice_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoice_documents_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'A stored issued invoice PDF never changes (ADR-075).';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER invoice_documents_immutable BEFORE UPDATE ON invoice_documents
                FOR EACH ROW EXECUTE FUNCTION invoice_documents_immutable();
        SQL);
    }
};
