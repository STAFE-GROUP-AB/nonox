<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Koncernöversikt (ADR-060): a user's own named set of companies, for
 * internal reporting. The group belongs to the user, not to a company, so it
 * holds no company data; every read goes through the user's memberships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('company_group_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_group_id')->constrained('company_groups')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            // Ownership share in basis points (10000 = 100 %), shown only.
            $table->unsignedSmallInteger('ownership_bp')->nullable();
            $table->timestamps();

            $table->unique(['company_group_id', 'company_id']);
            $table->index('company_id');
        });

        Schema::create('company_group_marks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_group_id')->constrained('company_groups')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedSmallInteger('account_number');
            $table->string('kind', 20);
            $table->timestamps();

            $table->unique(['company_group_id', 'company_id', 'account_number']);
        });
    }
};
