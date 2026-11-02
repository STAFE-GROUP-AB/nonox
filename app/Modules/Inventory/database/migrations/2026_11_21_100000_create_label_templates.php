<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Labels (ADR-041): label templates per company for article and location
 * labels, and on articles when their labels were last printed. Sizes are
 * millimetres for layout (not money), so numeric is fine here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            // ARTICLE or LOCATION
            $table->string('kind', 10);
            // SHEET (A4 grid) or ROLL (one label per page)
            $table->string('media', 10);
            $table->decimal('width_mm', 6, 2);
            $table->decimal('height_mm', 6, 2);
            // The free edge inside each label.
            $table->decimal('padding_mm', 4, 2)->default(2);
            // SHEET only: a key of LabelSheets (or custom), the grid and its position on A4.
            $table->string('sheet_preset', 30)->nullable();
            $table->unsignedSmallInteger('columns')->default(1);
            $table->unsignedSmallInteger('rows')->default(1);
            $table->decimal('sheet_top_mm', 6, 2)->default(0);
            $table->decimal('sheet_left_mm', 6, 2)->default(0);
            $table->decimal('pitch_x_mm', 6, 2)->default(0);
            $table->decimal('pitch_y_mm', 6, 2)->default(0);
            $table->boolean('cutting_guides')->default(false);
            // STACKED, SIDE or AUTO
            $table->string('layout', 10);
            // The LabelElement values shown, in order.
            $table->jsonb('elements');
            // The article property keys shown with the properties element.
            $table->jsonb('property_keys')->nullable();
            $table->string('free_text', 120)->nullable();
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        // One default template per company and kind.
        DB::statement('CREATE UNIQUE INDEX label_templates_one_default ON label_templates (company_id, kind) WHERE is_default');

        Schema::table('articles', function (Blueprint $table) {
            $table->timestamp('labels_printed_at')->nullable();
            $table->unsignedInteger('labels_printed_count')->default(0);
        });
    }
};
