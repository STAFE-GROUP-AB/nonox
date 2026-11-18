<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Intercompany counterparties and row tags (ADR-060, addendum 2026-11-07,
 * 2.3; ADR-081 E23, S2-1.3). Both tables are the company's own data: a
 * customer or supplier marked as a group company, and a tag beside a posted
 * voucher row saying which group company and what kind of intra-group item
 * it is. The other group company is named by organisation number only, never
 * by another tenant's id.
 *
 * The tags are classification metadata, not räkenskapsinformation: they are
 * never a column in voucher_rows, and nothing here touches voucher_rows,
 * the voucher triggers or the hash chain. The triggers below only check
 * that a tag (or a marking) points at a row of its own company, and that a
 * tagged row belongs to a posted voucher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intercompany_counterparties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->cascadeOnDelete();
            $table->string('counterparty_org_number', 11);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'customer_id']);
            $table->unique(['company_id', 'supplier_id']);
            $table->index(['company_id', 'counterparty_org_number']);
        });

        DB::statement('ALTER TABLE intercompany_counterparties ADD CONSTRAINT intercompany_counterparties_one_party CHECK ((customer_id IS NULL) <> (supplier_id IS NULL))');

        Schema::create('intercompany_row_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('voucher_row_id')->unique()->constrained('voucher_rows')->restrictOnDelete();
            $table->string('counterparty_org_number', 11);
            $table->string('kind', 40);
            $table->string('source', 20);
            $table->foreignId('tagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'counterparty_org_number', 'kind']);
        });

        DB::statement("ALTER TABLE intercompany_row_tags ADD CONSTRAINT intercompany_row_tags_source_check CHECK (source IN ('COUNTERPARTY', 'ACCOUNT_MARK', 'MANUAL'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION intercompany_counterparties_same_company() RETURNS trigger AS $$
            BEGIN
                IF NEW.customer_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM customers WHERE id = NEW.customer_id AND company_id = NEW.company_id) THEN
                    RAISE EXCEPTION 'An intercompany counterparty must be a customer of the same company.';
                END IF;

                IF NEW.supplier_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM suppliers WHERE id = NEW.supplier_id AND company_id = NEW.company_id) THEN
                    RAISE EXCEPTION 'An intercompany counterparty must be a supplier of the same company.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql SET search_path = public, pg_temp;

            CREATE TRIGGER intercompany_counterparties_same_company
                BEFORE INSERT OR UPDATE ON intercompany_counterparties
                FOR EACH ROW EXECUTE FUNCTION intercompany_counterparties_same_company();

            CREATE OR REPLACE FUNCTION intercompany_row_tags_posted_row() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM voucher_rows r JOIN vouchers v ON v.id = r.voucher_id
                    WHERE r.id = NEW.voucher_row_id AND r.company_id = NEW.company_id AND v.company_id = NEW.company_id AND v.status = 'POSTED'
                ) THEN
                    RAISE EXCEPTION 'An intercompany tag must point at a row of a posted voucher of the same company.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql SET search_path = public, pg_temp;

            CREATE TRIGGER intercompany_row_tags_posted_row
                BEFORE INSERT OR UPDATE ON intercompany_row_tags
                FOR EACH ROW EXECUTE FUNCTION intercompany_row_tags_posted_row();
        SQL);
    }
};
