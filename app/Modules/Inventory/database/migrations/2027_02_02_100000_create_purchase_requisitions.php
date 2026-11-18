<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inköpsanmodan (ADR-081 gap 3, E9): someone asks for goods or services, the
 * request goes through the approval engine, and a buyer turns approved rows
 * into rows of a purchase order. A requisition is a request, not a
 * commitment: nothing here creates a voucher.
 *
 * Like a purchase order it carries an approval round (the engine's subject is
 * the requisition and the round), raised whenever it is saved again or taken
 * back, so an approval never covers something other than what the approver saw.
 * A requisition row names its purchase order row once converted; that link is
 * released if the order row goes away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedInteger('number');
            // DRAFT, SUBMITTED, APPROVED, REJECTED, CONVERTED, CANCELLED
            $table->string('status', 20)->default('DRAFT');
            $table->foreignId('requested_by')->constrained('users');
            // Whoever saved it last: a preparer for segregation of duties, like the creator.
            $table->foreignId('prepared_by')->nullable()->constrained('users');
            $table->date('needed_by')->nullable();
            $table->string('reason', 500)->nullable();
            $table->unsignedSmallInteger('approval_round')->default(1);
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->timestamp('rejected_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'requested_by']);
        });

        Schema::create('purchase_requisition_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('purchase_requisition_id')->constrained('purchase_requisitions')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('article_id')->nullable()->constrained('articles');
            $table->string('description');
            // The quantity as typed, in thousandths of the row's unit.
            $table->bigInteger('quantity_milli');
            $table->foreignId('unit_id')->constrained('units');
            // Per the row's unit, in öre; empty when the requester does not know.
            $table->bigInteger('estimated_price_ore')->nullable();
            $table->foreignId('account_id')->nullable()->constrained('accounts');
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers');
            $table->foreignId('project_id')->nullable()->constrained('projects');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');
            $table->foreignId('purchase_order_row_id')->nullable()->constrained('purchase_order_rows')->nullOnDelete();
            $table->timestamps();

            $table->index(['purchase_requisition_id', 'position']);
            $table->index(['company_id', 'purchase_order_row_id']);
        });

        DB::statement('ALTER TABLE purchase_requisition_rows ADD CONSTRAINT purchase_requisition_rows_quantity_positive CHECK (quantity_milli > 0)');
        DB::statement('ALTER TABLE purchase_requisition_rows ADD CONSTRAINT purchase_requisition_rows_price_not_negative CHECK (estimated_price_ore IS NULL OR estimated_price_ore >= 0)');
    }
};
