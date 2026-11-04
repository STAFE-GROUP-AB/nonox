<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Företagsguiden (ADR-051): the company's industry and start date, which
 * decide the steps and tips that apply, whether the guide is shown, and the
 * steps of the start plan the company has done. Progress stores who ticked
 * a step as a user id only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('industry', 32)->nullable();
            $table->date('started_on')->nullable();
            $table->boolean('guide_enabled')->default(true);
        });

        Schema::create('company_guide_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('step_key', 100);
            $table->timestamp('completed_at');
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'step_key']);
        });
    }
};
