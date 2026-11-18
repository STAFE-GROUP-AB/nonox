<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The consolidation engine and its run log (ADR-060, addendum 2026-11-07,
 * 2.4; ADR-081 E24, S2-1.4).
 *
 * group_account_maps: where one member company's account goes in the group
 * (a deviation from identity; identity rows are never stored). The row is
 * the member company's own data (company_id = the company whose account is
 * mapped), so it is company-scoped. The mapping stays within the account
 * class (first digit), so an asset never becomes a cost.
 *
 * corporate_group_runs: one stored consolidation, with the hash of its
 * inputs and of its output. Spans companies like corporate_groups and holds
 * only consolidated figures, never one company's. Append-only by its own
 * trigger. Nothing here writes into, or refers to, any company's vouchers:
 * consolidation never touches a member's books.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_account_maps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('corporate_group_id')->constrained('corporate_groups')->cascadeOnDelete();
            $table->unsignedSmallInteger('account_number');
            $table->unsignedSmallInteger('group_account_number');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'corporate_group_id', 'account_number']);
            $table->index(['corporate_group_id', 'company_id']);
        });

        DB::statement('ALTER TABLE group_account_maps ADD CONSTRAINT group_account_maps_range CHECK (account_number BETWEEN 1000 AND 8999 AND group_account_number BETWEEN 1000 AND 8999)');
        DB::statement('ALTER TABLE group_account_maps ADD CONSTRAINT group_account_maps_same_class CHECK (account_number / 1000 = group_account_number / 1000)');
        DB::statement('ALTER TABLE group_account_maps ADD CONSTRAINT group_account_maps_not_identity CHECK (account_number <> group_account_number)');

        Schema::create('corporate_group_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('corporate_group_id')->constrained('corporate_groups')->restrictOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->foreignId('run_by')->constrained('users');
            $table->jsonb('inputs');
            $table->char('inputs_hash', 64);
            $table->jsonb('output');
            $table->char('output_hash', 64);
            $table->boolean('preliminary');
            $table->timestamp('created_at');

            $table->index(['corporate_group_id', 'created_at']);
            $table->index(['corporate_group_id', 'inputs_hash']);
        });

        DB::statement('ALTER TABLE corporate_group_runs ADD CONSTRAINT corporate_group_runs_period CHECK (period_from <= period_to)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION corporate_group_runs_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Consolidation runs cannot be changed or deleted.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER corporate_group_runs_append_only
                BEFORE UPDATE OR DELETE ON corporate_group_runs
                FOR EACH ROW EXECUTE FUNCTION corporate_group_runs_append_only();
            SQL);
    }
};
