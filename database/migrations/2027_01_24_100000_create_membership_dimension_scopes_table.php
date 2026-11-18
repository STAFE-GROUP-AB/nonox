<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Dimension scopes (ADR-081 gap 10, E1): an owner or admin may limit a member
 * to a set of cost centres and projects. A scope belongs to the membership it
 * was set on (team_user.id), like a group role (ADR-060): when the person
 * leaves and is invited again, the old rows no longer match and nothing is
 * revived.
 *
 * team_user.dimension_scoped says whether the membership is limited at all, so
 * an unlimited member costs no query: the flag comes with the membership row
 * Jetstream already reads. Absence of rows on a limited membership means it
 * reaches nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_dimension_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // No foreign key on purpose: the id must outlive the membership row, so a new membership never matches it.
            $table->unsignedBigInteger('team_user_id');
            $table->string('dimension', 20);
            $table->unsignedBigInteger('object_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'user_id', 'dimension', 'object_id'], 'membership_dimension_scopes_unique');
            $table->index(['company_id', 'team_user_id'], 'membership_dimension_scopes_membership');
        });

        DB::statement("ALTER TABLE membership_dimension_scopes ADD CONSTRAINT membership_dimension_scopes_dimension CHECK (dimension IN ('COST_CENTER', 'PROJECT'))");

        Schema::table('team_user', function (Blueprint $table): void {
            $table->boolean('dimension_scoped')->default(false);
        });
    }
};
