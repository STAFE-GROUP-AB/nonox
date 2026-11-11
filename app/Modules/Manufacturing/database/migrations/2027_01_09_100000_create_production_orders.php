<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Tillverkningsorder (ADR-071 §8): a production order makes a quantity of
 * an article from its own copy of the bill of materials, with components
 * and operations. Components leave the stock as PRODUCTION_ISSUE at their
 * valuation cost, the finished goods come in as PRODUCTION_RECEIPT at the
 * manufacturing cost. Nothing here books a voucher: the books meet the
 * stock at the year-end valuation, which counts the issued components and
 * the reported labour of unfinished orders as work in progress.
 *
 * Issues and labour are append-only ledgers (corrected by a new row), so
 * the work in progress can be valued on any past day. The workshop
 * terminal (M5) writes labour rows with source TERMINAL; the planning
 * calendar (M6) adds its own columns to production_order_operations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            // From the number series production_orders, shown as TO-<number>.
            $table->unsignedInteger('number');
            $table->string('status', 20);
            $table->foreignId('article_id')->constrained('articles');
            // The version the copy was made from, kept when that version is switched off.
            $table->foreignId('bill_of_materials_id')->nullable()->constrained('bills_of_materials');
            $table->string('name');
            // The batch the copy describes, and the order's quantity, in thousandths of the article's stock unit.
            $table->bigInteger('yield_milli');
            $table->bigInteger('quantity_milli');
            $table->string('entered_quantity', 20)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('units');
            // Where the finished goods are received.
            $table->foreignId('location_id')->nullable()->constrained('stock_locations');
            // The customer order row it serves; stock production has none.
            $table->foreignUuid('sales_document_id')->nullable()->constrained('sales_documents');
            $table->uuid('sales_line_key')->nullable();
            $table->date('planned_start_on')->nullable();
            $table->date('due_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users');
            $table->date('started_on')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users');
            $table->date('finished_on')->nullable();
            $table->foreignId('finished_by')->nullable()->constrained('users');
            $table->bigInteger('produced_milli')->nullable();
            $table->date('cancelled_on')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->string('cancel_reason')->nullable();
            // The manufacturing cost at finish (öre) and the receipt's cost per stock unit.
            $table->bigInteger('material_cost_ore')->nullable();
            $table->bigInteger('labour_cost_ore')->nullable();
            $table->bigInteger('overhead_cost_ore')->nullable();
            $table->unsignedInteger('overhead_bp')->nullable();
            $table->bigInteger('unit_cost_ore')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'article_id']);
            $table->index(['sales_document_id', 'sales_line_key']);
        });

        DB::statement("ALTER TABLE production_orders ADD CONSTRAINT production_orders_status CHECK (status IN ('DRAFT', 'RELEASED', 'STARTED', 'FINISHED', 'CANCELLED'))");
        DB::statement('ALTER TABLE production_orders ADD CONSTRAINT production_orders_quantities_positive CHECK (yield_milli > 0 AND quantity_milli > 0 AND (produced_milli IS NULL OR produced_milli > 0))');

        // The order's copy of the bill of materials: components (ADR-071 §4, §8).
        Schema::create('production_order_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('component_article_id')->constrained('articles');
            // Net quantity for the batch and loss, as on bom_rows (ADR-073 RS-C2).
            $table->bigInteger('quantity_milli');
            $table->unsignedSmallInteger('loss_bp')->default(0);
            $table->string('entered_quantity', 20)->nullable();
            $table->string('entered_unit', 10)->nullable();
            $table->string('note')->nullable();
            // What the whole order needs, loss included, and what has been issued (sums of production_order_issues).
            $table->bigInteger('required_milli');
            $table->bigInteger('issued_milli')->default(0);
            $table->bigInteger('issued_value_ore')->default(0);
            $table->timestamps();

            $table->index(['production_order_id', 'position']);
            $table->index(['company_id', 'component_article_id']);
        });

        DB::statement('ALTER TABLE production_order_components ADD CONSTRAINT production_order_components_quantities CHECK (quantity_milli > 0 AND required_milli > 0 AND issued_milli >= 0 AND loss_bp < 10000)');

        // The order's operations. M6 adds workstation, remaining-minutes override and waiting time here.
        Schema::create('production_order_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('operation_id')->constrained('operations');
            // Standard minutes for the batch, and for the whole order.
            $table->unsignedInteger('minutes');
            $table->unsignedInteger('planned_minutes');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['production_order_id', 'position']);
            $table->index(['company_id', 'operation_id']);
        });

        DB::statement('ALTER TABLE production_order_operations ADD CONSTRAINT production_order_operations_minutes CHECK (minutes > 0)');

        // Components out of stock (positive) and back after a cancellation (negative), each with its stock movement.
        Schema::create('production_order_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders');
            $table->foreignId('production_order_component_id')->constrained('production_order_components');
            $table->foreignId('article_id')->constrained('articles');
            $table->foreignId('location_id')->constrained('stock_locations');
            $table->foreignId('stock_movement_id')->constrained('stock_movements');
            $table->bigInteger('quantity_milli');
            // The valuation cost that left the stock (negative on a return).
            $table->bigInteger('value_ore');
            $table->date('issued_on');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'production_order_id']);
            $table->index(['company_id', 'issued_on']);
        });

        DB::statement('ALTER TABLE production_order_issues ADD CONSTRAINT production_order_issues_quantity CHECK (quantity_milli <> 0)');

        // Direct labour on an order: planned minutes or minutes entered at finish now, the terminal (M5) later.
        Schema::create('production_labour_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders');
            $table->foreignId('production_order_operation_id')->constrained('production_order_operations');
            $table->string('source', 20);
            // Signed, so a correction is a new row; the hourly cost as it was when the minutes were recorded.
            $table->integer('minutes');
            $table->bigInteger('hourly_cost_ore');
            $table->bigInteger('cost_ore');
            $table->date('worked_on');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'production_order_id']);
            $table->index(['production_order_operation_id']);
            $table->index(['company_id', 'worked_on']);
        });

        DB::statement("ALTER TABLE production_labour_entries ADD CONSTRAINT production_labour_entries_source CHECK (source IN ('PLANNED', 'ENTERED', 'TERMINAL'))");
        DB::statement('ALTER TABLE production_labour_entries ADD CONSTRAINT production_labour_entries_minutes CHECK (minutes <> 0 AND hourly_cost_ore >= 0)');

        // Records: never changed or removed (the function is Lager's, ADR-039).
        foreach (['production_order_issues', 'production_labour_entries'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION inventory_append_only()");
        }
    }
};
