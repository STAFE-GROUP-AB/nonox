<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Saved and shared reports of the analysis view (ADR-081 gap 7, E18).
 *
 * A row holds the choices of an analysis (keys only: dataset, dimensions,
 * measure, period, filters), never SQL and never results. Opening a report
 * runs the choices again with the viewer's own permissions and dimension
 * scope, so sharing a report never shows more than the viewer may read.
 * `visibility` is PRIVATE (the owner only) or COMPANY (everyone who may use
 * the dataset); `pinned` shows the report as a tile on the overview.
 * The owner is nullable: when the person leaves, their private reports are
 * deleted and the ones shared with the company stay, without an owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->string('dataset', 40);
            $table->jsonb('definition');
            $table->string('visibility', 10)->default('PRIVATE');
            $table->boolean('pinned')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'visibility']);
            $table->index(['company_id', 'owner_user_id']);
        });

        DB::statement("ALTER TABLE report_definitions ADD CONSTRAINT report_definitions_visibility CHECK (visibility IN ('PRIVATE', 'COMPANY'))");
    }
};
