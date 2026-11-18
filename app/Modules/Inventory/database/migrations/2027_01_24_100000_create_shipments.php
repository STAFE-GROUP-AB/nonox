<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Packing and shipments (ADR-081 E14, gap 4): a shipment is made from a
 * completed pick list, holds packages with weight and dimensions, and each
 * package lists its contents with batch or serial number. The ledger is not
 * touched: the goods left stock when the pick list was completed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('pick_list_id')->constrained('pick_lists');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->foreignUuid('sales_document_id')->nullable()->constrained('sales_documents');
            // DRAFT (being packed), SHIPPED or CANCELLED.
            $table->string('status', 12)->default('DRAFT');
            $table->string('carrier', 60)->nullable();
            $table->string('tracking_number', 60)->nullable();
            $table->timestamp('shipped_at')->nullable();
            // The recipient as it stood when the shipment left, so the delivery note does not change afterwards.
            $table->text('ship_to')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('shipped_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        // One live shipment per pick list: the goods of a list are packed once.
        DB::statement("CREATE UNIQUE INDEX shipments_one_live_per_pick_list ON shipments (pick_list_id) WHERE status <> 'CANCELLED'");

        Schema::create('shipment_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->timestamps();

            $table->index(['shipment_id', 'position']);
        });

        Schema::create('shipment_package_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('shipment_package_id')->constrained('shipment_packages')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles');
            $table->foreignId('lot_id')->nullable()->constrained('stock_lots');
            $table->bigInteger('quantity_milli');
            $table->timestamps();

            $table->index(['company_id', 'lot_id']);
        });

        DB::statement('ALTER TABLE shipment_package_lines ADD CONSTRAINT shipment_package_lines_positive CHECK (quantity_milli > 0)');
        DB::statement('CREATE UNIQUE INDEX shipment_package_lines_package_article_lot_unique ON shipment_package_lines (shipment_package_id, article_id, COALESCE(lot_id, 0))');
    }
};
