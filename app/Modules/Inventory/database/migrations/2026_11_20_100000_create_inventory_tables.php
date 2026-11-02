<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lager (ADR-039): stock locations as a tree, the append-only ledger of
 * stock movements with the balance per article and location kept in step,
 * goods receipts, pick lists, stocktakes, year-end valuations, and the
 * article register extended (additively) with the fields a stock system
 * needs. Quantities are whole thousandths of the article's unit
 * (quantity_milli), money is whole öre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('stock_locations');
            // WAREHOUSE (Lager) → ZONE (Zon) → SHELF (Hylla/Ställ) → BIN (Pallplats/Fack).
            $table->string('type', 20);
            $table->string('code', 20);
            // The printable code: the codes from the warehouse down, joined with "-" (HL-A-03-2).
            $table->string('full_code', 120);
            // Codes padded for sorting, so a pick list walks the warehouse in order.
            $table->string('sort_key', 400);
            $table->string('name')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('city')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'full_code']);
            $table->index(['company_id', 'sort_key']);
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->string('sku', 60)->nullable();
            $table->string('gtin', 14)->nullable();
            $table->string('scanner_code', 64)->nullable();
            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->text('description')->nullable();
            // [{key, value}, ...]
            $table->jsonb('properties')->nullable();
            $table->jsonb('colours')->nullable();
            $table->jsonb('materials')->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->bigInteger('purchase_price_ore')->nullable();
            // STANDARD or VMB (vinstmarginalbeskattning for used goods).
            $table->string('vat_treatment', 10)->default('STANDARD');
            $table->foreignId('default_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('supplier_article_number', 60)->nullable();
            $table->boolean('is_stock_item')->default(false);
            $table->bigInteger('reorder_point_milli')->nullable();
            $table->bigInteger('reorder_quantity_milli')->nullable();
            $table->foreignId('default_location_id')->nullable()->constrained('stock_locations')->nullOnDelete();
            // Lägsta värdets princip: a net realisable value per unit set by hand, used when lower than cost.
            $table->bigInteger('net_realisable_value_ore')->nullable();
            $table->string('net_realisable_value_note')->nullable();

            $table->index(['company_id', 'gtin']);
            $table->index(['company_id', 'scanner_code']);
            $table->index(['company_id', 'sku']);
        });

        Schema::create('article_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('alt')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'position']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles');
            $table->foreignId('location_id')->constrained('stock_locations');
            $table->bigInteger('quantity_milli');
            // Cost per whole unit, kept on every movement into stock (receipts, returns, counts found).
            $table->bigInteger('unit_cost_ore')->nullable();
            $table->string('type', 20);
            $table->date('occurred_on');
            $table->string('source_type')->nullable();
            $table->string('source_id', 36)->nullable();
            // Both halves of a move between locations share it.
            $table->uuid('transfer_group')->nullable();
            $table->string('reason', 40)->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'article_id', 'occurred_on']);
            $table->index(['company_id', 'location_id']);
            $table->index(['source_type', 'source_id']);
        });

        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_not_zero CHECK (quantity_milli <> 0)');

        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles');
            $table->foreignId('location_id')->constrained('stock_locations');
            $table->bigInteger('quantity_milli')->default(0);
            $table->timestamps();

            $table->unique(['article_id', 'location_id']);
            $table->index(['company_id', 'location_id']);
        });

        Schema::create('stock_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');
            $table->foreignUuid('supplier_invoice_id')->nullable()->constrained('supplier_invoices');
            // OPEN, PARTIAL, RECEIVED, CANCELLED
            $table->string('status', 12)->default('OPEN');
            $table->date('expected_on')->nullable();
            $table->string('reference')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('stock_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('stock_receipt_id')->constrained('stock_receipts')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles');
            $table->foreignId('location_id')->nullable()->constrained('stock_locations');
            $table->bigInteger('ordered_milli');
            $table->bigInteger('received_milli')->default(0);
            $table->bigInteger('unit_cost_ore')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('pick_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('source_type')->nullable();
            $table->string('source_id', 36)->nullable();
            $table->string('source_label')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            // OPEN, COMPLETED, CANCELLED
            $table->string('status', 12)->default('OPEN');
            $table->string('note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('pick_list_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('pick_list_id')->constrained('pick_lists')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles');
            $table->foreignId('location_id')->nullable()->constrained('stock_locations');
            $table->bigInteger('quantity_milli');
            $table->bigInteger('picked_milli')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedInteger('number');
            // The location (and everything under it) that is counted; null = every location.
            $table->foreignId('location_id')->nullable()->constrained('stock_locations');
            // OPEN, COMPLETED, CANCELLED
            $table->string('status', 12)->default('OPEN');
            $table->date('counted_on');
            $table->string('note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'number']);
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles');
            $table->foreignId('location_id')->constrained('stock_locations');
            // The system quantity when the count started.
            $table->bigInteger('system_milli');
            $table->bigInteger('counted_milli')->nullable();
            $table->string('reason', 40)->nullable();
            $table->timestamps();

            $table->unique(['stock_count_id', 'article_id', 'location_id']);
        });

        Schema::create('stock_valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->nullable()->constrained('fiscal_years');
            $table->date('valuation_date');
            // FIFO or AVERAGE
            $table->string('method', 10);
            $table->bigInteger('cost_value_ore');
            // After lägsta värdets princip.
            $table->bigInteger('value_ore');
            $table->bigInteger('book_value_ore')->default(0);
            $table->bigInteger('booked_change_ore')->default(0);
            $table->unsignedSmallInteger('stock_account');
            $table->unsignedSmallInteger('change_account');
            $table->jsonb('lines');
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'fiscal_year_id']);
        });

        // The movement ledger and the valuations are records: never changed or removed.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION inventory_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only: rows cannot be changed or deleted.', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER stock_movements_append_only
                BEFORE UPDATE OR DELETE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION inventory_append_only();

            CREATE TRIGGER stock_valuations_append_only
                BEFORE UPDATE OR DELETE ON stock_valuations
                FOR EACH ROW EXECUTE FUNCTION inventory_append_only();
        SQL);
    }
};
