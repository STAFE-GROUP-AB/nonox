<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Behovsplanering (ADR-081 gap 5, E16): a planning run over weekly buckets
 * and the planned orders it proposes. Nothing here is bookkeeping and
 * nothing here is an order: a person turns a planned order into a draft
 * purchase or production order. Runs are deleted with their planned orders
 * after 30 days.
 *
 * Quantities are milli-units of the article's stock unit. The pegging lists
 * the demand a planned order covers (ids, numbers, dates and quantities
 * only). The run's messages are the advice about existing orders (move a
 * receipt forward, a receipt that is not needed), in the same form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mrp_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            // The Monday the first weekly bucket starts on, and the number of buckets (13 by default, at most 52).
            $table->date('starts_on');
            $table->unsignedSmallInteger('horizon_weeks');
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            // SHA-256 of the inputs as canonical JSON, so two runs on the same data can be recognised.
            $table->char('inputs_hash', 64);
            $table->string('engine_version', 10);
            $table->unsignedInteger('article_count')->default(0);
            $table->unsignedInteger('planned_order_count')->default(0);
            $table->unsignedInteger('exception_count')->default(0);
            $table->unsignedInteger('message_count')->default(0);
            $table->jsonb('messages')->default('[]');
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'created_at']);
        });

        DB::statement('ALTER TABLE mrp_runs ADD CONSTRAINT mrp_runs_horizon_check CHECK (horizon_weeks BETWEEN 1 AND 52 AND EXTRACT(ISODOW FROM starts_on) = 1)');

        Schema::create('mrp_planned_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('mrp_run_id')->constrained('mrp_runs')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles');
            // PURCHASE or PRODUCTION
            $table->string('kind', 10);
            $table->bigInteger('quantity_milli');
            $table->date('release_on');
            $table->date('due_on');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->jsonb('pegging')->default('[]');
            // RELEASE_NOW or LATE
            $table->string('exception', 15)->nullable();
            // The draft it became: purchase_order or production_order, and its id.
            $table->string('converted_type', 20)->nullable();
            $table->unsignedBigInteger('converted_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'mrp_run_id']);
            $table->index(['company_id', 'article_id']);
        });

        DB::statement("ALTER TABLE mrp_planned_orders ADD CONSTRAINT mrp_planned_orders_values_check CHECK (quantity_milli > 0 AND kind IN ('PURCHASE', 'PRODUCTION') AND (exception IS NULL OR exception IN ('RELEASE_NOW', 'LATE')) AND due_on >= release_on AND (converted_type IS NULL OR converted_type IN ('purchase_order', 'production_order')) AND ((converted_type IS NULL) = (converted_id IS NULL)))");
    }
};
