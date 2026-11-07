<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Review findings on S2-1.1 (ADR-060, addendum 2026-11-07, "S2-1.1 as built"):
 * - a group role belongs to the membership in the parent it was granted
 *   under, so it does not revive when its holder leaves and is invited again;
 * - one undisposed holding per holder and held company, also in the database;
 * - a holding that grounds a membership cannot be deleted (NO ACTION, checked
 *   at the end of the statement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corporate_group_roles', function (Blueprint $table): void {
            // team_user.id of the holder's membership in the parent when granted; null when granted to the owner.
            // No foreign key on purpose: the id must outlive the row, so a new membership never matches it.
            $table->unsignedBigInteger('parent_membership_id')->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX company_holdings_one_active ON company_holdings (company_id, held_org_number) WHERE disposed_on IS NULL');

        Schema::table('corporate_group_members', function (Blueprint $table): void {
            $table->dropForeign(['holding_id']);
            $table->foreign('holding_id')->references('id')->on('company_holdings')->noActionOnDelete();
        });
    }
};
