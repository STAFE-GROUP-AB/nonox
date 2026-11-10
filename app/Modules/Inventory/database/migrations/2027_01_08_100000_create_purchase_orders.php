<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inköpsorder (ADR-071 §2, §5, §12): what the company has ordered from a
 * supplier, the versions it sent, the supplier invoices it was matched with,
 * and the goods receipts that tick off its rows. A purchase order is a
 * commitment, not bookkeeping: nothing here creates a voucher.
 *
 * A row keeps its quantity in milli-units of the article's stock unit and,
 * beside it, what was typed in the row's own unit (a unit from the company's
 * catalogue); its price is per that unit, in öre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedInteger('number');
            // Raised each time a sent order is changed; the sent versions are kept in purchase_order_versions.
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('supplier_id')->constrained('suppliers');
            // DRAFT, SENT, PARTIALLY_RECEIVED, RECEIVED, CANCELLED
            $table->string('status', 20)->default('DRAFT');
            // MANUAL, PROPOSAL, PRODUCTION_ORDER
            $table->string('source', 20)->default('MANUAL');
            $table->date('order_date');
            $table->date('requested_delivery_on')->nullable();
            $table->string('our_reference', 100)->nullable();
            $table->string('delivery_address', 500)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('sent_at')->nullable();
            $table->string('sent_to')->nullable();
            // EMAIL or PRINT
            $table->string('sent_method', 10)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'supplier_id']);
        });

        Schema::create('purchase_order_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('article_id')->constrained('articles');
            $table->string('supplier_article_number', 60)->nullable();
            $table->string('description');
            // What was typed, in the row's own unit (a decimal with a point), and that unit.
            $table->string('entered_quantity', 20);
            $table->foreignId('unit_id')->constrained('units');
            // The same quantity in milli-units of the article's stock unit, rounded half up once.
            $table->bigInteger('quantity_milli');
            // Price per the row's unit, in öre.
            $table->bigInteger('price_ore');
            $table->date('expected_on')->nullable();
            // Ticked off by goods receipts, in milli-units of the stock unit.
            $table->bigInteger('received_milli')->default(0);
            $table->timestamps();

            $table->index(['purchase_order_id', 'position']);
            $table->index(['company_id', 'article_id']);
        });

        DB::statement('ALTER TABLE purchase_order_rows ADD CONSTRAINT purchase_order_rows_quantity_positive CHECK (quantity_milli > 0)');
        DB::statement('ALTER TABLE purchase_order_rows ADD CONSTRAINT purchase_order_rows_price_not_negative CHECK (price_ore >= 0)');
        DB::statement('ALTER TABLE purchase_order_rows ADD CONSTRAINT purchase_order_rows_received_not_negative CHECK (received_milli >= 0)');

        Schema::create('purchase_order_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            // The order as it was sent: header and rows, so the supplier's copy can be shown again.
            $table->jsonb('snapshot');
            $table->bigInteger('total_ore');
            // EMAIL or PRINT
            $table->string('method', 10);
            $table->string('sent_to')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users');
            $table->timestamp('sent_at');

            $table->unique(['purchase_order_id', 'version']);
        });

        Schema::create('purchase_order_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignUuid('supplier_invoice_id')->constrained('supplier_invoices')->cascadeOnDelete();
            $table->foreignId('linked_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['purchase_order_id', 'supplier_invoice_id']);
            $table->index(['company_id', 'supplier_invoice_id']);
        });

        Schema::table('stock_receipts', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders');
            $table->index(['company_id', 'purchase_order_id']);
        });

        Schema::table('stock_receipt_lines', function (Blueprint $table) {
            $table->foreignId('purchase_order_row_id')->nullable()->constrained('purchase_order_rows');
        });
    }
};
