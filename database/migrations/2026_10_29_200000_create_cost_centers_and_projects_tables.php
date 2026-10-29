<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kostnadsställen and projekt (AGENTS.md §6.5), the two dimensions a voucher
 * row can carry; SIE dimension 1 and 6. A code is never reused or removed
 * once rows refer to it, only deactivated.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['cost_centers', 'projects'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
                $table->string('code', 20);
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['company_id', 'code']);
            });
        }

        // The columns have existed since the vouchers table so rows never change shape.
        Schema::table('voucher_rows', function (Blueprint $table) {
            $table->foreign('cost_center_id')->references('id')->on('cost_centers');
            $table->foreign('project_id')->references('id')->on('projects');
        });

        Schema::table('voucher_row_history', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers');
            $table->foreignId('project_id')->nullable()->constrained('projects');
        });
    }
};
