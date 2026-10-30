<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional modules per company (PAYROLL_MODULE.md A.4). A row is kept when a
 * module is turned off, so its settings and history survive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('module_key', 50);
            $table->boolean('enabled')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->foreignId('enabled_by')->nullable()->constrained('users');
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users');
            $table->jsonb('settings')->default('{}');
            $table->timestamps();

            $table->unique(['company_id', 'module_key']);
        });
    }
};
