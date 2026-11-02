<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The bank's answers to a payment file (ADR-014): status reports
 * (pain.002) and debit notifications or statements (camt.054, camt.053),
 * and payments out found by NONOX Bank. Each answer is kept as the bank gave
 * it, with what it was matched to and what was done. A payment in a file
 * learns whether the bank rejected it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payment_batch_items', function (Blueprint $table) {
            // ACCEPTED or REJECTED, as the bank last reported it before the payment was booked.
            $table->string('bank_status', 10)->nullable();
            $table->timestamp('bank_status_at')->nullable();
            $table->string('reason_code', 10)->nullable();
            $table->string('reason_text', 500)->nullable();
        });

        Schema::create('payment_response_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('format', 10);
            $table->string('file_name');
            $table->string('file_path');
            $table->char('file_sha256', 64);
            $table->string('message_id', 35)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            // The same file is read once.
            $table->unique(['company_id', 'file_sha256']);
        });

        Schema::create('payment_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('payment_response_import_id')->nullable()->constrained('payment_response_imports');
            // A payment out fetched by NONOX Bank (ADR-034) rather than read from a file.
            $table->unsignedBigInteger('bank_feed_transaction_id')->nullable()->index();
            $table->string('kind', 10);
            // What makes the answer unique: the bank's reference, or a fingerprint of it.
            $table->string('reference', 120);
            $table->string('original_message_id', 35)->nullable();
            $table->string('payment_info_id', 35)->nullable();
            $table->string('end_to_end_id', 35)->nullable();
            $table->bigInteger('amount_ore')->nullable();
            $table->char('currency', 3)->nullable();
            $table->date('date')->nullable();
            $table->string('creditor_account', 34)->nullable();
            $table->string('creditor_name')->nullable();
            $table->string('reason_code', 10)->nullable();
            $table->string('reason_text', 500)->nullable();
            $table->string('match', 10)->nullable();
            $table->foreignUuid('batch_id')->nullable()->constrained('supplier_payment_batches');
            $table->foreignId('batch_item_id')->nullable()->constrained('supplier_payment_batch_items');
            $table->string('status', 12);
            $table->string('note', 30)->nullable();
            $table->foreignId('supplier_payment_id')->nullable()->constrained('supplier_payments');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'kind', 'reference']);
            $table->index(['company_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER payment_response_imports_append_only
                BEFORE UPDATE OR DELETE ON payment_response_imports
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();

            -- An answer keeps what the bank said; only an open one is settled, once.
            CREATE OR REPLACE FUNCTION payment_responses_protect() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'payment responses cannot be deleted'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF (to_jsonb(NEW) - 'match' - 'batch_id' - 'batch_item_id' - 'status' - 'note' - 'supplier_payment_id' - 'resolved_at' - 'resolved_by' - 'updated_at')
                     <> (to_jsonb(OLD) - 'match' - 'batch_id' - 'batch_item_id' - 'status' - 'note' - 'supplier_payment_id' - 'resolved_at' - 'resolved_by' - 'updated_at')
                   OR OLD.status NOT IN ('SUGGESTED', 'UNMATCHED') THEN
                    RAISE EXCEPTION 'payment response % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payment_responses_protect
                BEFORE UPDATE OR DELETE ON payment_responses
                FOR EACH ROW EXECUTE FUNCTION payment_responses_protect();

            -- Items gain the payment that settled them, or the bank's status
            -- while unpaid; a paid or rejected item never changes again.
            CREATE OR REPLACE FUNCTION supplier_payment_batch_items_protect() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'payment batch items cannot be deleted'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF (to_jsonb(NEW) - 'supplier_payment_id' - 'bank_status' - 'bank_status_at' - 'reason_code' - 'reason_text' - 'updated_at')
                     <> (to_jsonb(OLD) - 'supplier_payment_id' - 'bank_status' - 'bank_status_at' - 'reason_code' - 'reason_text' - 'updated_at')
                   OR OLD.supplier_payment_id IS NOT NULL
                   OR OLD.bank_status IS NOT DISTINCT FROM 'REJECTED'
                   OR (NEW.supplier_payment_id IS NOT NULL AND NEW.bank_status IS NOT DISTINCT FROM 'REJECTED') THEN
                    RAISE EXCEPTION 'payment batch items cannot be changed'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);
    }
};
