<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A shared group (ADR-060, addendum 2026-11-07, 2.1 and 2.2): a parent
 * company, the member companies each confirmed by an owner or administrator
 * of that company, and the explicit group roles. None of the tables is
 * company-scoped (they span companies) and none holds ledger data; who may
 * see what is decided live by App\Domain\Group\GroupAccess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_groups', function (Blueprint $table): void {
            $table->id();
            // One authoritative structure per parent (ÅRL 7 kap. 1 §: the parent prepares the group accounts).
            $table->foreignId('parent_company_id')->unique()->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('corporate_group_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('corporate_group_id')->constrained('corporate_groups')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            // The holding (in the holder's books) that makes the company a member; null for the parent.
            $table->foreignId('holding_id')->nullable()->constrained('company_holdings')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->unique(['corporate_group_id', 'company_id']);
            $table->index('company_id');
            $table->index('holding_id');
        });

        Schema::create('corporate_group_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('corporate_group_id')->constrained('corporate_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at');
            $table->timestamps();

            $table->unique(['corporate_group_id', 'user_id', 'role']);
            $table->index('user_id');
        });
    }
};
