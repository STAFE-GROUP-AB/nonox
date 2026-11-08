<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Components in the fixed asset register (K3 17.4, 17.13; ADR-059 A.2.4,
 * S1-P4). A component is a fixed asset with a parent of the same company;
 * the parent gets the status SPLIT and is never depreciated again. A
 * component created by a split writes off its carrying amount over its
 * remaining life (depreciation_basis CARRYING_AMOUNT); every existing asset
 * keeps COST and behaves as before. Land has no accumulated or expense
 * account, so those columns become nullable. No existing row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('depreciation_basis', 20)->default('COST');
            $table->integer('accumulated_account')->nullable()->change();
            $table->integer('expense_account')->nullable()->change();

            // The parent must belong to the same company: the foreign key includes company_id.
            $table->unique(['id', 'company_id']);
            $table->index('parent_id');
        });

        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->foreign(['parent_id', 'company_id'])->references(['id', 'company_id'])->on('fixed_assets');
        });

        DB::statement("ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_depreciation_basis_check CHECK (depreciation_basis IN ('COST', 'CARRYING_AMOUNT'))");
        DB::statement("ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_status_check CHECK (status IN ('ACTIVE', 'DISPOSED', 'SPLIT'))");
        DB::statement('ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_parent_not_self CHECK (parent_id IS NULL OR parent_id <> id)');
    }
};
