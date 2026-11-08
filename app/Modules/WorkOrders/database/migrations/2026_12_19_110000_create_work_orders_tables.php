<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Arbetsorder (ADR-074): work orders with billable time and material,
 * change orders (ÄTA), payment plans and photos.
 *
 * Time and material entries are append-only (§3): a wrong entry is
 * corrected by a reversing entry, as vouchers are. The trigger allows only
 * two changes to a stored entry: setting reversed_by_id once, and the
 * invoice it is reserved by while that invoice is a draft (released when
 * the draft is deleted, fixed once it is issued). Entries are never
 * deleted.
 *
 * A change order is immutable once decided (approved, approved orally,
 * performed without the customer's answer, declined, expired or
 * withdrawn), and its content cannot change while it is sent (AO-R25).
 * Contact attempts are append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_labour_kinds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('type', 10);
            $table->string('unit', 10);
            $table->bigInteger('default_rate_ore')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->foreignUuid('sales_document_id')->nullable()->constrained('sales_documents')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('site_address_line1', 255)->nullable();
            $table->string('site_postal_code', 10)->nullable();
            $table->string('site_city', 100)->nullable();
            $table->string('property_designation', 255)->nullable();
            $table->string('housing_association_org_number', 13)->nullable();
            $table->string('apartment_number', 10)->nullable();
            $table->text('access_notes')->nullable();
            $table->string('price_form', 20);
            $table->bigInteger('contract_sum_ore')->nullable();
            $table->bigInteger('contract_labour_ore')->nullable();
            $table->bigInteger('approximate_price_ore')->nullable();
            $table->unsignedSmallInteger('approximate_limit_bp')->nullable();
            $table->unsignedSmallInteger('material_markup_bp')->default(0);
            $table->unsignedSmallInteger('vat_rate')->default(2500);
            $table->jsonb('rates')->nullable();
            $table->string('tax_reduction', 3)->nullable();
            $table->boolean('reverse_charge')->default(false);
            $table->boolean('is_construction')->default(true);
            $table->string('standard_contract', 100)->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_price_form CHECK (price_form IN ('TIME_AND_MATERIAL', 'FIXED', 'ESTIMATE'))");
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_status CHECK (status IN ('DRAFT', 'PLANNED', 'IN_PROGRESS', 'DONE', 'CLOSED', 'CANCELLED'))");
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_tax_reduction CHECK (tax_reduction IS NULL OR tax_reduction IN ('ROT', 'RUT'))");
        // ROT/RUT buyers are private persons, reverse charge buyers are construction businesses (AO-R20).
        DB::statement('ALTER TABLE work_orders ADD CONSTRAINT work_orders_rot_or_reverse_charge CHECK (NOT (reverse_charge AND tax_reduction IS NOT NULL))');

        Schema::create('work_order_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['work_order_id', 'user_id']);
            $table->index(['company_id', 'user_id']);
        });

        Schema::create('work_order_buyers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers');
            $table->unsignedSmallInteger('share_bp');
            $table->timestamps();
            $table->unique(['work_order_id', 'customer_id']);
        });

        Schema::create('change_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders');
            $table->unsignedInteger('number');
            $table->string('title', 255);
            $table->text('description');
            $table->string('basis', 40);
            $table->string('price_form', 20);
            $table->bigInteger('labour_ore')->default(0);
            $table->bigInteger('material_ore')->default(0);
            $table->bigInteger('other_ore')->default(0);
            $table->bigInteger('limit_ore')->nullable();
            $table->string('time_effect', 255)->nullable();
            $table->string('payment_terms', 255)->nullable();
            $table->boolean('tax_reduction_applies')->default(true);
            $table->string('status', 30)->default('DRAFT');
            $table->timestamp('sent_at')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('approval_method', 20)->nullable();
            $table->string('approved_by_name', 255)->nullable();
            $table->string('approved_by_title', 255)->nullable();
            $table->string('oral_channel', 20)->nullable();
            $table->string('performed_reason', 30)->nullable();
            $table->text('justification')->nullable();
            $table->char('document_sha256', 64)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['work_order_id', 'number']);
        });

        DB::statement("ALTER TABLE change_orders ADD CONSTRAINT change_orders_status CHECK (status IN ('DRAFT', 'SENT', 'APPROVED', 'APPROVED_ORALLY', 'PERFORMED_WITHOUT_ANSWER', 'DECLINED', 'EXPIRED', 'WITHDRAWN'))");
        DB::statement("ALTER TABLE change_orders ADD CONSTRAINT change_orders_basis CHECK (basis IN ('CHANGE_ORDERED', 'ADDITIONAL_WORK_8', 'CUSTOMER_CIRCUMSTANCES_38_2'))");
        DB::statement("ALTER TABLE change_orders ADD CONSTRAINT change_orders_price_form CHECK (price_form IN ('FIXED', 'TIME_AND_MATERIAL', 'ESTIMATE'))");
        DB::statement("ALTER TABLE change_orders ADD CONSTRAINT change_orders_performed_reason CHECK (performed_reason IS NULL OR performed_reason IN ('LOW_PRICE', 'PRESUMED_WISH', 'URGENT_DAMAGE'))");

        Schema::create('change_order_contact_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('change_order_id')->constrained('change_orders');
            $table->timestamp('attempted_at');
            $table->string('channel', 20);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('work_order_time_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders');
            $table->foreignId('change_order_id')->nullable()->constrained('change_orders');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('labour_kind_id')->constrained('work_order_labour_kinds');
            $table->date('work_date');
            $table->integer('minutes')->nullable();
            $table->bigInteger('quantity_milli')->nullable();
            $table->boolean('billable')->default(true);
            $table->bigInteger('rate_ore');
            $table->string('note', 255)->nullable();
            $table->string('source', 30);
            $table->timestamp('entered_at');
            $table->uuid('reverses_id')->nullable();
            $table->uuid('reversed_by_id')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'work_order_id']);
            $table->index(['company_id', 'user_id', 'work_date']);
        });

        DB::statement('ALTER TABLE work_order_time_entries ADD CONSTRAINT work_order_time_entries_amount CHECK ((minutes IS NOT NULL AND minutes <> 0 AND quantity_milli IS NULL) OR (minutes IS NULL AND quantity_milli IS NOT NULL AND quantity_milli <> 0))');

        Schema::create('work_order_material_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders');
            $table->foreignId('change_order_id')->nullable()->constrained('change_orders');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('article_id')->nullable()->constrained('articles');
            $table->string('description', 255);
            $table->bigInteger('quantity_milli');
            $table->string('unit', 10)->default('st');
            $table->bigInteger('unit_cost_ore')->default(0);
            $table->bigInteger('unit_price_ore')->default(0);
            $table->unsignedSmallInteger('vat_rate')->default(2500);
            $table->boolean('billable')->default(true);
            $table->string('source', 30);
            $table->foreignId('stock_location_id')->nullable();
            $table->foreignId('supplier_invoice_row_id')->nullable()->constrained('supplier_invoice_rows');
            $table->date('work_date');
            $table->string('note', 255)->nullable();
            $table->timestamp('entered_at');
            $table->uuid('reverses_id')->nullable();
            $table->uuid('reversed_by_id')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'work_order_id']);
        });

        foreach (['work_order_time_entries', 'work_order_material_entries'] as $entries) {
            Schema::table($entries, function (Blueprint $table) use ($entries) {
                $table->foreign('reverses_id')->references('id')->on($entries);
                $table->foreign('reversed_by_id')->references('id')->on($entries);
            });
        }

        DB::statement('ALTER TABLE work_order_material_entries ADD CONSTRAINT work_order_material_entries_quantity CHECK (quantity_milli <> 0)');
        DB::statement("ALTER TABLE work_order_material_entries ADD CONSTRAINT work_order_material_entries_source CHECK (source IN ('STOCK', 'SUPPLIER_INVOICE_ROW', 'FREE'))");
        // A supplier invoice row is billed once (ADR-074 §3): one live entry per row.
        DB::statement('CREATE UNIQUE INDEX work_order_material_entries_supplier_row_once ON work_order_material_entries (supplier_invoice_row_id) WHERE supplier_invoice_row_id IS NOT NULL AND reverses_id IS NULL AND reversed_by_id IS NULL');

        Schema::create('work_order_payment_instalments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('label', 255);
            $table->bigInteger('amount_ore');
            $table->date('due_on')->nullable();
            $table->string('milestone', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('work_order_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders');
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->foreignId('instalment_id')->nullable()->constrained('work_order_payment_instalments')->nullOnDelete();
            $table->bigInteger('contract_net_ore')->default(0);
            $table->jsonb('change_order_ids')->nullable();
            $table->timestamps();
            $table->unique('invoice_id');
        });

        Schema::create('work_order_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders');
            $table->foreignId('change_order_id')->nullable()->constrained('change_orders');
            $table->string('path', 255);
            $table->string('caption', 255)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION work_order_entries_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION '% rows cannot be removed; reverse the entry instead', TG_TABLE_NAME
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.reversed_by_id IS NOT NULL AND NEW.reversed_by_id IS DISTINCT FROM OLD.reversed_by_id THEN
                    RAISE EXCEPTION 'entry % is already reversed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF NEW.invoice_id IS DISTINCT FROM OLD.invoice_id AND OLD.invoice_id IS NOT NULL
                   AND EXISTS (SELECT 1 FROM invoices WHERE id = OLD.invoice_id AND status <> 'DRAFT') THEN
                    RAISE EXCEPTION 'entry % is invoiced', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF (to_jsonb(NEW) - 'reversed_by_id' - 'invoice_id' - 'updated_at')
                   <> (to_jsonb(OLD) - 'reversed_by_id' - 'invoice_id' - 'updated_at') THEN
                    RAISE EXCEPTION '% rows cannot be changed; reverse the entry instead', TG_TABLE_NAME
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER work_order_time_entries_append_only
                BEFORE UPDATE OR DELETE ON work_order_time_entries
                FOR EACH ROW EXECUTE FUNCTION work_order_entries_append_only();

            CREATE TRIGGER work_order_material_entries_append_only
                BEFORE UPDATE OR DELETE ON work_order_material_entries
                FOR EACH ROW EXECUTE FUNCTION work_order_entries_append_only();

            CREATE OR REPLACE FUNCTION change_orders_protect_decided() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'change order % cannot be removed', OLD.id
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status NOT IN ('DRAFT', 'SENT') THEN
                    RAISE EXCEPTION 'decided change order % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.status = 'SENT'
                   AND (to_jsonb(NEW) - 'status' - 'decided_at' - 'approval_method' - 'approved_by_name' - 'approved_by_title' - 'decided_by' - 'updated_at')
                     <> (to_jsonb(OLD) - 'status' - 'decided_at' - 'approval_method' - 'approved_by_name' - 'approved_by_title' - 'decided_by' - 'updated_at') THEN
                    RAISE EXCEPTION 'sent change order % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER change_orders_protect_decided
                BEFORE UPDATE OR DELETE ON change_orders
                FOR EACH ROW EXECUTE FUNCTION change_orders_protect_decided();

            CREATE TRIGGER change_order_contact_attempts_append_only
                BEFORE UPDATE OR DELETE ON change_order_contact_attempts
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            SQL);
    }
};
