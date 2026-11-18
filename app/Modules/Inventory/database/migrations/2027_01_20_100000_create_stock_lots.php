<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch and serial numbers (ADR-081 E12, gap 4): an article is tracked per
 * batch or per serial number; each batch or serial is a lot, stock is kept
 * per location and lot next to the article's total, and the movement ledger
 * carries the lot. Valuation stays per article (owner decision 12), so no
 * cost is kept on a lot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            // NONE, BATCH or SERIAL.
            $table->string('tracking', 10)->default('NONE');
            $table->boolean('expiry_required')->default(false);
        });

        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles');
            // BATCH or SERIAL.
            $table->string('kind', 10);
            $table->string('code', 60);
            $table->date('expires_on')->nullable();
            $table->date('received_on');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('stock_receipt_line_id')->nullable()->constrained('stock_receipt_lines')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'article_id', 'kind', 'code']);
            $table->index(['company_id', 'code']);
        });

        Schema::create('stock_lot_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles');
            $table->foreignId('location_id')->constrained('stock_locations');
            $table->foreignId('lot_id')->constrained('stock_lots');
            $table->bigInteger('quantity_milli')->default(0);
            $table->timestamps();

            $table->unique(['lot_id', 'location_id']);
            $table->index(['company_id', 'article_id', 'location_id']);
        });

        DB::statement('ALTER TABLE stock_lot_levels ADD CONSTRAINT stock_lot_levels_not_negative CHECK (quantity_milli >= 0)');

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('lot_id')->nullable()->constrained('stock_lots');
            $table->index(['company_id', 'lot_id']);
        });

        Schema::table('pick_list_lines', function (Blueprint $table) {
            $table->foreignId('lot_id')->nullable()->constrained('stock_lots');
        });

        Schema::table('stock_count_lines', function (Blueprint $table) {
            $table->foreignId('lot_id')->nullable()->constrained('stock_lots');
        });

        // One count line per article, location and lot (a plain line has no lot).
        // The old unique constraint (count, article, location): found by what it covers, not by a guessed name.
        DB::unprepared(<<<'SQL'
            DO $$
            DECLARE old_name text;
            BEGIN
                FOR old_name IN
                    SELECT conname FROM pg_constraint
                    WHERE conrelid = 'stock_count_lines'::regclass AND contype = 'u' AND array_length(conkey, 1) = 3
                LOOP
                    EXECUTE format('ALTER TABLE stock_count_lines DROP CONSTRAINT %I', old_name);
                END LOOP;
            END $$;
        SQL);
        DB::statement('CREATE UNIQUE INDEX stock_count_lines_count_article_location_lot_unique ON stock_count_lines (stock_count_id, article_id, location_id, COALESCE(lot_id, 0))');
    }
};
