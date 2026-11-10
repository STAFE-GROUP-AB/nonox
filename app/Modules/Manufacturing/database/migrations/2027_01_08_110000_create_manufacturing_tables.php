<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Tillverkning, first step (ADR-071 §3, §4, §6): operations (arbetssteg)
 * with an hourly cost, operation rows on bills of materials (the structure
 * engine's bom_rows, ADR-073), and the copy of a bill of materials that a
 * quote or order row keeps for itself. Nothing here is bookkeeping: the
 * costing is a management figure and writes no voucher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            // What one hour of the step costs the company, set by the company (ADR-071 §6).
            $table->bigInteger('hourly_cost_ore');
            // Staffed minutes per working day the step can take; null = not stated.
            $table->unsignedInteger('daily_capacity_minutes')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        DB::statement('ALTER TABLE operations ADD CONSTRAINT operations_hourly_cost_not_negative CHECK (hourly_cost_ore >= 0)');

        // A row is either a component or an operation with its minutes for the batch.
        Schema::table('bom_rows', function (Blueprint $table) {
            $table->foreign('operation_id')->references('id')->on('operations');
            $table->index(['company_id', 'operation_id']);
        });

        DB::statement('ALTER TABLE bom_rows ADD CONSTRAINT bom_rows_component_or_operation CHECK ((component_article_id IS NOT NULL AND operation_id IS NULL) OR (component_article_id IS NULL AND operation_id IS NOT NULL AND minutes > 0))');

        // A quote or order row's own bill of materials (ADR-071 §4), on the row's stable key.
        Schema::create('sales_document_row_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('sales_document_id')->constrained('sales_documents')->cascadeOnDelete();
            $table->uuid('line_key');
            // What the copy makes, and the version it was copied from (kept when that version is switched off).
            $table->foreignId('output_article_id')->constrained('articles');
            $table->foreignId('bill_of_materials_id')->nullable()->constrained('bills_of_materials');
            $table->string('name');
            // The batch the copy describes, in thousandths of the output article's stock unit.
            $table->bigInteger('yield_milli');
            $table->boolean('changed')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['sales_document_id', 'line_key']);
            $table->index(['company_id', 'bill_of_materials_id']);
        });

        DB::statement('ALTER TABLE sales_document_row_structures ADD CONSTRAINT sales_document_row_structures_yield_positive CHECK (yield_milli > 0)');

        Schema::create('sales_document_row_structure_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('row_structure_id')->constrained('sales_document_row_structures')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('component_article_id')->nullable()->constrained('articles');
            // In thousandths of the component's stock unit; loss in basis points of the gross (ADR-073 RS-C2).
            $table->bigInteger('quantity_milli')->default(0);
            $table->unsignedSmallInteger('loss_bp')->default(0);
            $table->string('entered_quantity', 20)->nullable();
            $table->string('entered_unit', 10)->nullable();
            $table->foreignId('operation_id')->nullable()->constrained('operations');
            $table->unsignedInteger('minutes')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['row_structure_id', 'position']);
        });

        DB::statement('ALTER TABLE sales_document_row_structure_lines ADD CONSTRAINT sales_document_row_structure_lines_loss_range CHECK (loss_bp < 10000)');
        DB::statement('ALTER TABLE sales_document_row_structure_lines ADD CONSTRAINT sales_document_row_structure_lines_kind CHECK ((component_article_id IS NOT NULL AND operation_id IS NULL AND quantity_milli > 0) OR (component_article_id IS NULL AND operation_id IS NOT NULL AND minutes > 0))');
    }
};
