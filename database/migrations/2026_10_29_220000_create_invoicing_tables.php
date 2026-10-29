<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customers, articles and customer invoices (AGENTS.md §7 Phase 2), with
 * number series for customers and invoices (§3.4).
 *
 * An invoice is a draft until it is issued: then it gets its number and its
 * contents never change again. Payments are kept per invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('key', 30);
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('name');
            $table->string('org_number', 13)->nullable();
            // Personal numbers are encrypted at rest (AGENTS.md §10).
            $table->text('personal_number')->nullable();
            $table->string('vat_number', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city')->nullable();
            $table->char('country', 2)->default('SE');
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'number']);
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('name');
            $table->string('unit', 10)->default('st');
            $table->bigInteger('price_ore')->default(0);
            // Basis points: 2500, 1200, 600 or 0.
            $table->unsignedSmallInteger('vat_rate');
            $table->foreignId('account_id')->constrained('accounts');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'number']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers');
            $table->unsignedInteger('number')->nullable();
            $table->enum('kind', ['INVOICE', 'CREDIT'])->default('INVOICE');
            $table->uuid('credits_invoice_id')->nullable();
            $table->enum('status', ['DRAFT', 'ISSUED', 'PAID', 'CREDITED'])->default('DRAFT');
            $table->date('invoice_date');
            $table->date('due_date');
            $table->date('delivery_date')->nullable();
            $table->string('your_reference')->nullable();
            $table->string('our_reference')->nullable();
            $table->text('notes')->nullable();
            // The customer as it was when the invoice was issued; the register may change later.
            $table->jsonb('customer_snapshot')->nullable();
            $table->bigInteger('net_ore')->default(0);
            $table->bigInteger('vat_ore')->default(0);
            $table->bigInteger('rounding_ore')->default(0);
            $table->bigInteger('total_ore')->default(0);
            $table->bigInteger('paid_ore')->default(0);
            $table->string('ocr', 30)->nullable();
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users');
            $table->timestamp('sent_at')->nullable();
            $table->string('sent_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('credits_invoice_id')->references('id')->on('invoices');
        });

        Schema::create('invoice_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles');
            $table->string('description', 255);
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 10)->default('st');
            $table->bigInteger('unit_price_ore');
            $table->unsignedSmallInteger('discount_bp')->default(0);
            $table->unsignedSmallInteger('vat_rate');
            $table->foreignId('account_id')->constrained('accounts');
            $table->bigInteger('net_ore');
            $table->unsignedSmallInteger('position');
            $table->timestamps();
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->date('paid_on');
            $table->bigInteger('amount_ore');
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignUuid('voucher_id')->constrained('vouchers');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE invoices ADD CONSTRAINT invoices_issued_has_number
                CHECK (status = 'DRAFT' OR (number IS NOT NULL AND issued_at IS NOT NULL));

            -- An issued invoice keeps its contents: only status, payments,
            -- sending and the link to a credit invoice may change.
            CREATE OR REPLACE FUNCTION invoices_protect_issued() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'issued invoice % cannot be deleted', OLD.id
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status <> 'DRAFT'
                   AND (to_jsonb(NEW) - 'status' - 'paid_ore' - 'sent_at' - 'sent_to' - 'updated_at')
                     <> (to_jsonb(OLD) - 'status' - 'paid_ore' - 'sent_at' - 'sent_to' - 'updated_at') THEN
                    RAISE EXCEPTION 'issued invoice % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER invoices_protect_issued
                BEFORE UPDATE OR DELETE ON invoices
                FOR EACH ROW EXECUTE FUNCTION invoices_protect_issued();

            CREATE OR REPLACE FUNCTION invoice_rows_protect_issued() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM invoices WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.invoice_id ELSE NEW.invoice_id END AND status <> 'DRAFT') THEN
                    RAISE EXCEPTION 'rows of issued invoice cannot be changed'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$;

            CREATE TRIGGER invoice_rows_protect_issued
                BEFORE INSERT OR UPDATE OR DELETE ON invoice_rows
                FOR EACH ROW EXECUTE FUNCTION invoice_rows_protect_issued();

            CREATE TRIGGER invoice_payments_append_only
                BEFORE UPDATE OR DELETE ON invoice_payments
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            SQL);
    }
};
