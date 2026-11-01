<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting firms and their clients (ADR-030). A company can be marked as a
 * firm, and a client company can name one firm whose logo and colours its
 * users and employees then see. The link is branding only: it gives the
 * firm no access (access comes from memberships alone).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('is_accounting_firm')->default(false);
            $table->boolean('firm_brand_for_clients')->default(true);
            $table->foreignId('accounting_firm_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->timestamp('accounting_firm_linked_at')->nullable();
            $table->foreignId('accounting_firm_linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index('accounting_firm_id');
        });

        DB::statement('ALTER TABLE teams ADD CONSTRAINT teams_accounting_firm_not_self CHECK (accounting_firm_id IS NULL OR accounting_firm_id <> id)');
    }
};
