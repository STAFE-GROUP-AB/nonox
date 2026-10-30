<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Incoming payments read from the bank's files (ISO 20022 camt.054): the file
 * as received, and each credit in it with what it was matched to. A bank
 * transaction is booked at most once, whatever file it arrives in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_file_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->char('file_sha256', 64);
            $table->string('message_id', 35)->nullable();
            $table->unsignedInteger('credit_count');
            $table->bigInteger('credit_total_ore');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('bank_file_import_id')->constrained('bank_file_imports');
            // The bank's reference, or a fingerprint of the transaction when the bank gives none.
            $table->string('reference', 100);
            $table->date('booking_date');
            $table->bigInteger('amount_ore');
            $table->string('ocr', 35)->nullable();
            $table->string('message', 500)->nullable();
            $table->string('payer_name')->nullable();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices');
            $table->foreignId('invoice_payment_id')->nullable()->constrained('invoice_payments');
            $table->timestamps();

            $table->unique(['company_id', 'reference']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bank_file_imports_append_only
                BEFORE UPDATE OR DELETE ON bank_file_imports
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();

            -- A transaction keeps what the bank said; it only gains the invoice and payment it settled, once.
            CREATE OR REPLACE FUNCTION bank_transactions_protect() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'bank transactions cannot be deleted'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF (to_jsonb(NEW) - 'invoice_id' - 'invoice_payment_id' - 'updated_at') <> (to_jsonb(OLD) - 'invoice_id' - 'invoice_payment_id' - 'updated_at')
                   OR OLD.invoice_payment_id IS NOT NULL THEN
                    RAISE EXCEPTION 'bank transaction % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER bank_transactions_protect
                BEFORE UPDATE OR DELETE ON bank_transactions
                FOR EACH ROW EXECUTE FUNCTION bank_transactions_protect();
            SQL);
    }
};
