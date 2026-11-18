<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transfer orders between warehouses with goods in transit, reorder points
 * per warehouse, and the warehouse's cost centre (ADR-081 E13, gap 4). A
 * transit location is a stock location of type TRANSIT, one per pair of
 * warehouses, kept inactive so no picker offers it; goods on their way sit
 * there and are part of the article's total, so the valuation is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_locations', function (Blueprint $table) {
            // A warehouse is a unit through its cost centre (owner decision 23).
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            // Set on a transit location only: the warehouses it carries goods between.
            $table->foreignId('transit_from_id')->nullable()->constrained('stock_locations');
            $table->foreignId('transit_to_id')->nullable()->constrained('stock_locations');
            $table->unique(['company_id', 'transit_from_id', 'transit_to_id']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('from_location_id')->constrained('stock_locations');
            $table->foreignId('to_location_id')->constrained('stock_locations');
            $table->foreignId('transit_location_id')->nullable()->constrained('stock_locations');
            // DRAFT, SENT, PARTIALLY_RECEIVED, RECEIVED or CANCELLED.
            $table->string('status', 20)->default('DRAFT');
            $table->string('note', 255)->nullable();
            $table->date('sent_on')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles');
            $table->foreignId('lot_id')->nullable()->constrained('stock_lots');
            $table->bigInteger('quantity_milli');
            $table->bigInteger('received_milli')->default(0);
            // What went back to the sending location when the rest of the order was cancelled.
            $table->bigInteger('returned_milli')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'stock_transfer_id']);
        });

        DB::statement('ALTER TABLE stock_transfer_lines ADD CONSTRAINT stock_transfer_lines_quantities CHECK (quantity_milli > 0 AND received_milli >= 0 AND returned_milli >= 0 AND received_milli + returned_milli <= quantity_milli)');

        Schema::create('article_location_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('stock_locations');
            $table->bigInteger('reorder_point_milli');
            $table->bigInteger('reorder_quantity_milli')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'warehouse_id']);
            $table->index(['company_id', 'warehouse_id']);
        });

        DB::statement('ALTER TABLE article_location_settings ADD CONSTRAINT article_location_settings_not_negative CHECK (reorder_point_milli >= 0 AND (reorder_quantity_milli IS NULL OR reorder_quantity_milli >= 0))');
    }
};
