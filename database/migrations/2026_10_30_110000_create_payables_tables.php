<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leverantörsreskontra (AGENTS.md §7 Phase 2): suppliers, their invoices with
 * cost rows and the scanned invoice as underlag, approval (attest) and
 * payments. A registered supplier invoice keeps its contents; only its
 * status, approval and payments change afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // Segregation of duties (AGENTS.md §4): whoever registers a supplier invoice may not approve it.
            $table->boolean('separate_supplier_approval')->default(true);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('name');
            $table->string('org_number', 13)->nullable();
            $table->string('vat_number', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city')->nullable();
            $table->char('country', 2)->default('SE');
            $table->string('bankgiro', 12)->nullable();
            $table->string('plusgiro', 12)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('bic', 11)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->foreignId('default_account_id')->nullable()->constrained('accounts');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'number']);
        });

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers');
            // Our own running number, given when the invoice is registered.
            $table->unsignedInteger('number')->nullable();
            $table->enum('status', ['DRAFT', 'REGISTERED', 'APPROVED', 'PAID'])->default('DRAFT');
            $table->string('invoice_number', 50)->nullable();
            $table->string('ocr', 30)->nullable();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->string('description')->nullable();
            $table->bigInteger('total_ore')->default(0);
            $table->bigInteger('vat_ore')->default(0);
            $table->bigInteger('paid_ore')->default(0);
            // The supplier as it was when registered; the register may change later.
            $table->jsonb('supplier_snapshot')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->timestamp('registered_at')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'due_date']);
        });

        Schema::create('supplier_invoice_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('supplier_invoice_id')->constrained('supplier_invoices')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts');
            $table->string('description', 255)->nullable();
            // The cost excluding VAT.
            $table->bigInteger('amount_ore');
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers');
            $table->foreignId('project_id')->nullable()->constrained('projects');
            $table->unsignedSmallInteger('position');
            $table->timestamps();
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('supplier_invoice_id')->constrained('supplier_invoices');
            $table->date('paid_on');
            $table->bigInteger('amount_ore');
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignUuid('voucher_id')->constrained('vouchers');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_registered_has_number
                CHECK (status = 'DRAFT' OR (number IS NOT NULL AND registered_at IS NOT NULL));

            ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_approved_has_approver
                CHECK (status IN ('DRAFT', 'REGISTERED') OR (approved_at IS NOT NULL AND approved_by IS NOT NULL));

            -- A registered supplier invoice keeps its contents: only status,
            -- approval and payments may change.
            CREATE OR REPLACE FUNCTION supplier_invoices_protect_registered() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'registered supplier invoice % cannot be deleted', OLD.id
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status <> 'DRAFT'
                   AND (to_jsonb(NEW) - 'status' - 'paid_ore' - 'approved_at' - 'approved_by' - 'updated_at')
                     <> (to_jsonb(OLD) - 'status' - 'paid_ore' - 'approved_at' - 'approved_by' - 'updated_at') THEN
                    RAISE EXCEPTION 'registered supplier invoice % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER supplier_invoices_protect_registered
                BEFORE UPDATE OR DELETE ON supplier_invoices
                FOR EACH ROW EXECUTE FUNCTION supplier_invoices_protect_registered();

            CREATE OR REPLACE FUNCTION supplier_invoice_rows_protect_registered() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM supplier_invoices WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.supplier_invoice_id ELSE NEW.supplier_invoice_id END AND status <> 'DRAFT') THEN
                    RAISE EXCEPTION 'rows of registered supplier invoice cannot be changed'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$;

            CREATE TRIGGER supplier_invoice_rows_protect_registered
                BEFORE INSERT OR UPDATE OR DELETE ON supplier_invoice_rows
                FOR EACH ROW EXECUTE FUNCTION supplier_invoice_rows_protect_registered();

            CREATE TRIGGER supplier_payments_append_only
                BEFORE UPDATE OR DELETE ON supplier_payments
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            SQL);
    }
};
