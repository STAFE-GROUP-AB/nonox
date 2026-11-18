<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Intra-group transactions (ADR-081 gap 1, E27). Three tables, each the
 * company's own data, naming the other group company by organisation
 * number only, never by another tenant's id:
 *
 * - intercompany_receipt_settings (company_id = the receiver): the senders
 *   from which the company accepts intra-group invoices into its inbox.
 *   Nothing is delivered without a row here that is switched on.
 * - intercompany_deliveries (company_id = the sender): that an invoice was
 *   delivered, or refused. The sender keeps no id of the receiver's inbox
 *   message or supplier invoice, and a refusal keeps no reason.
 * - intercompany_pairs (one row in each company): a voucher and the
 *   counterpart's voucher it mirrors, by series and number as text.
 *
 * The triggers only check that a row points at an invoice or voucher of its
 * own company. Nothing here touches vouchers, voucher_rows, their triggers
 * or the hash chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intercompany_receipt_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('sender_org_number', 11);
            $table->boolean('enabled')->default(true);
            $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'sender_org_number']);
        });

        Schema::create('intercompany_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->string('recipient_org_number', 11);
            // Set only for a delivery: a refusal says nothing about which group, if any, was found.
            $table->foreignId('corporate_group_id')->nullable()->constrained('corporate_groups')->nullOnDelete();
            $table->string('status', 10);
            $table->timestampTz('delivered_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'invoice_id']);
            $table->index(['company_id', 'created_at']);
        });

        DB::statement("ALTER TABLE intercompany_deliveries ADD CONSTRAINT intercompany_deliveries_status_check CHECK (status IN ('DELIVERED', 'REFUSED'))");
        // A delivery has its time; a refusal has neither a time of delivery nor a group (the group may later be removed: null).
        DB::statement("ALTER TABLE intercompany_deliveries ADD CONSTRAINT intercompany_deliveries_delivered_check CHECK ((status = 'DELIVERED') = (delivered_at IS NOT NULL) AND (status = 'DELIVERED' OR corporate_group_id IS NULL))");
        // An invoice is delivered at most once.
        DB::statement("CREATE UNIQUE INDEX intercompany_deliveries_one_delivered ON intercompany_deliveries (company_id, invoice_id) WHERE status = 'DELIVERED'");

        Schema::create('intercompany_pairs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            // A draft in the receiving company may be deleted by its people; its pair row goes with it.
            $table->foreignUuid('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->string('counterparty_org_number', 11);
            $table->string('counterparty_voucher_ref', 40)->nullable();
            $table->string('kind', 40);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'voucher_id', 'counterparty_org_number']);
            $table->index(['company_id', 'counterparty_org_number']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION intercompany_deliveries_same_company() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM invoices WHERE id = NEW.invoice_id AND company_id = NEW.company_id) THEN
                    RAISE EXCEPTION 'An intercompany delivery must be of an invoice of the same company.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql SET search_path = public, pg_temp;

            CREATE TRIGGER intercompany_deliveries_same_company
                BEFORE INSERT OR UPDATE ON intercompany_deliveries
                FOR EACH ROW EXECUTE FUNCTION intercompany_deliveries_same_company();

            CREATE OR REPLACE FUNCTION intercompany_pairs_same_company() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM vouchers WHERE id = NEW.voucher_id AND company_id = NEW.company_id) THEN
                    RAISE EXCEPTION 'An intercompany pair must name a voucher of the same company.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql SET search_path = public, pg_temp;

            CREATE TRIGGER intercompany_pairs_same_company
                BEFORE INSERT OR UPDATE ON intercompany_pairs
                FOR EACH ROW EXECUTE FUNCTION intercompany_pairs_same_company();
        SQL);
    }
};
