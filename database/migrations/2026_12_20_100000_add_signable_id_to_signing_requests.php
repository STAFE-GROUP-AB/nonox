<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Signing moves from the CRM module to the core (ADR-075 §7): any document
 * that implements Signable can be sent for signing. The quote and the
 * agreement keep their own foreign keys; a later type (the work order,
 * ADR-074) keeps its key in signable_id. The table keeps its name. The
 * evidence trigger protects the new column as it protects the others.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_signing_requests', function (Blueprint $table) {
            $table->string('signable_id', 64)->nullable()->after('agreement_id');
            $table->index(['company_id', 'document_type', 'signable_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION crm_signing_requests_protect() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('SIGNED', 'DECLINED') THEN
                    IF NEW.status IS DISTINCT FROM OLD.status
                        OR NEW.company_id IS DISTINCT FROM OLD.company_id
                        OR NEW.document_type IS DISTINCT FROM OLD.document_type
                        OR NEW.sales_document_id IS DISTINCT FROM OLD.sales_document_id
                        OR NEW.agreement_id IS DISTINCT FROM OLD.agreement_id
                        OR NEW.signable_id IS DISTINCT FROM OLD.signable_id
                        OR NEW.token_hash IS DISTINCT FROM OLD.token_hash
                        OR NEW.email IS DISTINCT FROM OLD.email
                        OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                        OR NEW.document_path IS DISTINCT FROM OLD.document_path
                        OR NEW.document_name IS DISTINCT FROM OLD.document_name
                        OR NEW.document_sha256 IS DISTINCT FROM OLD.document_sha256
                        OR NEW.content_hash IS DISTINCT FROM OLD.content_hash
                        OR NEW.create_invoice IS DISTINCT FROM OLD.create_invoice
                        OR NEW.opened_at IS DISTINCT FROM OLD.opened_at
                        OR NEW.signer_name IS DISTINCT FROM OLD.signer_name
                        OR NEW.signer_title IS DISTINCT FROM OLD.signer_title
                        OR NEW.signature_method IS DISTINCT FROM OLD.signature_method
                        OR NEW.acceptance_text IS DISTINCT FROM OLD.acceptance_text
                        OR NEW.signature_image IS DISTINCT FROM OLD.signature_image
                        OR NEW.signed_at IS DISTINCT FROM OLD.signed_at
                        OR NEW.signer_ip IS DISTINCT FROM OLD.signer_ip
                        OR NEW.signer_user_agent IS DISTINCT FROM OLD.signer_user_agent
                        OR NEW.declined_at IS DISTINCT FROM OLD.declined_at
                        OR NEW.decline_reason IS DISTINCT FROM OLD.decline_reason
                        OR NEW.revoked_at IS DISTINCT FROM OLD.revoked_at
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR (OLD.signed_document_sha256 IS NOT NULL AND NEW.signed_document_sha256 IS DISTINCT FROM OLD.signed_document_sha256)
                        OR (OLD.signed_document_path IS NOT NULL AND NEW.signed_document_path IS DISTINCT FROM OLD.signed_document_path)
                        OR (OLD.order_id IS NOT NULL AND NEW.order_id IS DISTINCT FROM OLD.order_id)
                        OR (OLD.invoice_id IS NOT NULL AND NEW.invoice_id IS DISTINCT FROM OLD.invoice_id)
                        OR (OLD.followed_up_at IS NOT NULL AND NEW.followed_up_at IS DISTINCT FROM OLD.followed_up_at)
                    THEN
                        RAISE EXCEPTION 'A signed or declined signing request cannot be changed (ADR-046).';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
