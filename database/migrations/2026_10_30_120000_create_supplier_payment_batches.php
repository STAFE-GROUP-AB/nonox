<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment files to the bank (ISO 20022 pain.001.001.03) for approved
 * supplier invoices. A batch keeps the file exactly as it was made; when the
 * bank has paid, the batch is confirmed and each invoice gets its payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // The bank that receives payment files, and the agreement id the bank gave for file transfer.
            $table->string('payment_bank', 30)->nullable();
            $table->string('bank_agreement_id', 35)->nullable();
        });

        Schema::create('supplier_payment_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->enum('status', ['CREATED', 'CONFIRMED', 'CANCELLED'])->default('CREATED');
            $table->string('bank', 30);
            $table->date('execution_date');
            $table->string('debtor_iban', 34);
            $table->string('debtor_bic', 11);
            $table->string('message_id', 35);
            $table->unsignedInteger('payment_count');
            $table->bigInteger('total_ore');
            $table->string('file_path');
            $table->char('file_sha256', 64);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'message_id']);
        });

        Schema::create('supplier_payment_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('batch_id')->constrained('supplier_payment_batches');
            $table->foreignUuid('supplier_invoice_id')->constrained('supplier_invoices');
            $table->bigInteger('amount_ore');
            $table->string('end_to_end_id', 35);
            $table->foreignId('supplier_payment_id')->nullable()->constrained('supplier_payments');
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            -- A batch's file and contents never change; only its status moves on.
            CREATE OR REPLACE FUNCTION supplier_payment_batches_protect() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'payment batch % cannot be deleted', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF (to_jsonb(NEW) - 'status' - 'confirmed_at' - 'confirmed_by' - 'cancelled_at' - 'cancelled_by' - 'updated_at')
                     <> (to_jsonb(OLD) - 'status' - 'confirmed_at' - 'confirmed_by' - 'cancelled_at' - 'cancelled_by' - 'updated_at') THEN
                    RAISE EXCEPTION 'payment batch % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.status <> 'CREATED' AND NEW.status <> OLD.status THEN
                    RAISE EXCEPTION 'payment batch % is already %', OLD.id, OLD.status
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER supplier_payment_batches_protect
                BEFORE UPDATE OR DELETE ON supplier_payment_batches
                FOR EACH ROW EXECUTE FUNCTION supplier_payment_batches_protect();

            -- Items only gain the payment that settled them.
            CREATE OR REPLACE FUNCTION supplier_payment_batch_items_protect() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'payment batch items cannot be deleted'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF (to_jsonb(NEW) - 'supplier_payment_id' - 'updated_at') <> (to_jsonb(OLD) - 'supplier_payment_id' - 'updated_at')
                   OR OLD.supplier_payment_id IS NOT NULL THEN
                    RAISE EXCEPTION 'payment batch items cannot be changed'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER supplier_payment_batch_items_protect
                BEFORE UPDATE OR DELETE ON supplier_payment_batch_items
                FOR EACH ROW EXECUTE FUNCTION supplier_payment_batch_items_protect();
            SQL);
    }
};
