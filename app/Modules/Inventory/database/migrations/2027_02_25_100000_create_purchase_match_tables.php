<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three-way matching per row (ADR-081 gap 3, E11).
 *
 * purchase_match_allocations: which purchase order row a row of a registered
 * supplier invoice pays for, and the quantity the invoice charges for, in the
 * order row's own unit. One order row per invoice row.
 *
 * purchase_match_results: the stored comparison of order, goods receipt and
 * invoice per order row (and one per invoice row without an order row), with
 * the tolerances it was judged by. A result is never deleted. A new
 * comparison supersedes the old set (superseded_at, once), and an outside
 * result may be overridden once, with a reason, by someone allowed to attest;
 * nothing else about a result can change. The database enforces both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_match_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('supplier_invoice_id')->constrained('supplier_invoices');
            $table->foreignId('supplier_invoice_row_id')->unique()->constrained('supplier_invoice_rows');
            $table->foreignId('purchase_order_row_id')->constrained('purchase_order_rows');
            $table->bigInteger('invoiced_milli');
            $table->foreignId('allocated_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'supplier_invoice_id']);
            $table->index(['company_id', 'purchase_order_row_id']);
        });

        DB::statement('ALTER TABLE purchase_match_allocations ADD CONSTRAINT purchase_match_allocations_quantity CHECK (invoiced_milli >= 0)');

        Schema::create('purchase_match_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('supplier_invoice_id')->constrained('supplier_invoices');
            $table->foreignId('supplier_invoice_row_id')->nullable()->constrained('supplier_invoice_rows');
            $table->jsonb('invoice_row_ids');
            $table->foreignId('purchase_order_row_id')->nullable()->constrained('purchase_order_rows');
            // The latest goods receipt line with goods for the order row when the comparison was made. Kept
            // as a plain number: an open receipt's lines are rebuilt, and a result is never changed.
            $table->unsignedBigInteger('stock_receipt_line_id')->nullable();
            $table->bigInteger('ordered_milli')->default(0);
            $table->bigInteger('received_milli')->default(0);
            $table->bigInteger('previously_invoiced_milli')->default(0);
            $table->bigInteger('invoiced_milli')->default(0);
            $table->bigInteger('ordered_ore')->default(0);
            $table->bigInteger('received_ore')->default(0);
            $table->bigInteger('invoiced_ore')->default(0);
            $table->bigInteger('expected_ore')->default(0);
            $table->bigInteger('quantity_diff_milli')->default(0);
            $table->bigInteger('price_diff_ore')->default(0);
            $table->integer('quantity_tolerance_bp');
            $table->integer('price_tolerance_bp');
            $table->bigInteger('absolute_tolerance_ore');
            $table->string('status', 12);
            $table->jsonb('reasons');
            $table->char('fingerprint', 64);
            $table->string('override_reason', 500)->nullable();
            $table->foreignId('overridden_by')->nullable()->constrained('users');
            $table->timestampTz('overridden_at')->nullable();
            $table->foreignId('carried_from_id')->nullable()->constrained('purchase_match_results');
            $table->timestampTz('computed_at');
            $table->foreignId('computed_by')->nullable()->constrained('users');
            $table->timestampTz('superseded_at')->nullable();

            $table->index(['company_id', 'supplier_invoice_id', 'superseded_at']);
            $table->index(['company_id', 'purchase_order_row_id']);
        });

        DB::statement("ALTER TABLE purchase_match_results ADD CONSTRAINT purchase_match_results_status CHECK (status IN ('WITHIN', 'OUTSIDE', 'OVERRIDDEN', 'UNMATCHED'))");
        DB::statement("ALTER TABLE purchase_match_results ADD CONSTRAINT purchase_match_results_override CHECK ((status = 'OVERRIDDEN') = (override_reason IS NOT NULL AND overridden_by IS NOT NULL AND overridden_at IS NOT NULL))");
        DB::statement('ALTER TABLE purchase_match_results ADD CONSTRAINT purchase_match_results_tolerances CHECK (quantity_tolerance_bp >= 0 AND price_tolerance_bp >= 0 AND absolute_tolerance_ore >= 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION purchase_match_results_guard() RETURNS trigger AS $$
            DECLARE
                probe purchase_match_results;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Purchase match results cannot be deleted.';
                END IF;

                IF OLD.superseded_at IS NOT NULL THEN
                    RAISE EXCEPTION 'A superseded purchase match result cannot be changed.';
                END IF;

                probe := NEW;
                probe.superseded_at := OLD.superseded_at;

                IF OLD.override_reason IS NULL AND NEW.override_reason IS NOT NULL THEN
                    IF OLD.status NOT IN ('OUTSIDE', 'UNMATCHED') OR NEW.status <> 'OVERRIDDEN' THEN
                        RAISE EXCEPTION 'Only an outside or unmatched purchase match result can be overridden.';
                    END IF;

                    probe.status := OLD.status;
                    probe.override_reason := OLD.override_reason;
                    probe.overridden_by := OLD.overridden_by;
                    probe.overridden_at := OLD.overridden_at;
                END IF;

                IF probe IS DISTINCT FROM OLD THEN
                    RAISE EXCEPTION 'A purchase match result can only be superseded or overridden once.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER purchase_match_results_guard
                BEFORE UPDATE OR DELETE ON purchase_match_results
                FOR EACH ROW EXECUTE FUNCTION purchase_match_results_guard();
        SQL);
    }
};
