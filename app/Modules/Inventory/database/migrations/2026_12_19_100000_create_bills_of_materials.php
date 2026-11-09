<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The structure engine (ADR-073 §2, shared with ADR-071): an output article
 * made from quantities of other articles, with a loss per row, a yield,
 * versions dated by valid_from and sub-structures. Recipes (kind RECIPE)
 * and bills of materials (kind BOM) use the same tables. A version is never
 * changed after it is saved, only switched off; a change is a new version.
 *
 * Also: per-article unit conversions for recipe rows (a bottle of 70 cl),
 * the stock class on articles (RAW_MATERIAL or GOODS_FOR_RESALE, empty =
 * the company's default class), and the per-class split of a year-end
 * valuation. All additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills_of_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            // RECIPE (Kök) or BOM (manufacturing, ADR-071).
            $table->string('kind', 10);
            $table->foreignId('output_article_id')->constrained('articles');
            $table->string('name');
            $table->unsignedInteger('version');
            $table->date('valid_from');
            $table->boolean('is_active')->default(true);
            // The batch size: portions (PORTIONS) or the output article's unit (UNIT), in thousandths.
            $table->bigInteger('yield_milli');
            $table->string('yield_unit', 10);
            $table->text('method')->nullable();
            $table->jsonb('allergens')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'output_article_id', 'version']);
            $table->index(['company_id', 'kind', 'output_article_id', 'valid_from']);
        });

        DB::statement('ALTER TABLE bills_of_materials ADD CONSTRAINT bills_of_materials_yield_positive CHECK (yield_milli > 0)');

        Schema::create('bom_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('bill_of_materials_id')->constrained('bills_of_materials')->cascadeOnDelete();
            // Null for an operation row (ADR-071 adds operations); ignored when costing materials.
            $table->foreignId('component_article_id')->nullable()->constrained('articles');
            // Net quantity that ends in the product, in thousandths of the component's unit.
            $table->bigInteger('quantity_milli')->default(0);
            // Loss as a share of the gross quantity, in basis points: gross = net × 10 000 / (10 000 − loss_bp).
            $table->unsignedSmallInteger('loss_bp')->default(0);
            // What the person typed, for showing it again (1,5 dl), when it differs from the stored unit.
            $table->string('entered_quantity', 20)->nullable();
            $table->string('entered_unit', 10)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('note')->nullable();
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->unsignedInteger('minutes')->nullable();
            $table->timestamps();

            $table->index(['bill_of_materials_id', 'position']);
            $table->index(['company_id', 'component_article_id']);
        });

        DB::statement('ALTER TABLE bom_rows ADD CONSTRAINT bom_rows_loss_range CHECK (loss_bp < 10000)');
        DB::statement('ALTER TABLE bom_rows ADD CONSTRAINT bom_rows_quantity_positive CHECK (component_article_id IS NULL OR quantity_milli > 0)');

        Schema::create('article_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->string('unit', 10);
            // How many thousandths of this unit make one of the article's unit: a 70 cl bottle has cl = 70 000.
            $table->bigInteger('factor_milli');
            $table->timestamps();

            $table->unique(['article_id', 'unit']);
        });

        DB::statement('ALTER TABLE article_unit_conversions ADD CONSTRAINT article_unit_conversions_factor_positive CHECK (factor_milli > 0)');

        Schema::table('articles', function (Blueprint $table) {
            // RAW_MATERIAL (Råvaror, 1410/4910) or GOODS_FOR_RESALE (Handelsvaror, 1460/4960); null = the company's default.
            $table->string('stock_class', 20)->nullable();
        });

        Schema::table('stock_valuations', function (Blueprint $table) {
            // Per stock class: class, cost, value, accounts, book value and change.
            $table->jsonb('classes')->nullable();
            // The K2 97 % rule (K2 12.5) applied to this valuation.
            $table->boolean('rule_97')->default(false);
        });
    }
};
