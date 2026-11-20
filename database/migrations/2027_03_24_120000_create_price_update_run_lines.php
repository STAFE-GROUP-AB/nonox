<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mass price update, "Prisuppdatering" (ADR-082 §8, P6). Additive only: one
 * line per price a run looks at (the preview and, once applied, the diff
 * that stays when the uploaded file is pruned after two years, decision 10),
 * and three nullable columns on the runs of P1. Ids, enums, dates and minor
 * amounts only: a refused file row keeps the article number it named, never
 * a name or other free text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_update_run_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('price_update_run_id')->constrained('price_update_runs');
            // The row of the file, or the order of the plan.
            $table->integer('position');
            $table->string('target', 10);
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists');
            $table->foreignId('article_id')->nullable()->constrained('articles');
            // What a file row named when no article of the company has that number.
            $table->string('article_number', 50)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('units');
            $table->bigInteger('min_quantity_milli')->default(0);
            // The price in force on the line's date that the line replaces.
            $table->foreignId('current_item_id')->nullable()->constrained('price_list_items');
            $table->bigInteger('old_minor')->nullable();
            $table->integer('old_discount_bp')->nullable();
            $table->bigInteger('unrounded_minor')->nullable();
            $table->bigInteger('new_minor')->nullable();
            $table->integer('new_discount_bp')->nullable();
            $table->bigInteger('cost_minor')->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('status', 20);
            $table->string('reason', 40)->nullable();
            $table->jsonb('flags')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'price_update_run_id', 'position']);
        });

        Schema::table('price_update_runs', function (Blueprint $table): void {
            $table->string('file_format', 10)->nullable();
            $table->timestamp('file_deleted_at')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->foreignId('reverted_by')->nullable()->constrained('users');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE price_update_run_lines ADD CONSTRAINT price_update_run_lines_target_check
                CHECK (target IN ('BASE', 'LIST'));
            ALTER TABLE price_update_run_lines ADD CONSTRAINT price_update_run_lines_status_check
                CHECK (status IN ('READY', 'UNCHANGED', 'REFUSED', 'EXCLUDED', 'APPLIED', 'SKIPPED', 'REVERTED'));
            ALTER TABLE price_update_run_lines ADD CONSTRAINT price_update_run_lines_list_check
                CHECK (target <> 'LIST' OR price_list_id IS NOT NULL);
            ALTER TABLE price_update_run_lines ADD CONSTRAINT price_update_run_lines_new_check
                CHECK (new_minor IS NULL OR new_minor >= 0);
            ALTER TABLE price_update_runs ADD CONSTRAINT price_update_runs_file_format_check
                CHECK (file_format IS NULL OR file_format IN ('csv', 'xlsx'));
        SQL);
    }
};
