<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Year-end closing (bokslut, AGENTS.md Phase 3): the checklist points a
 * person has ticked, and the closing entries booked for a fiscal year (tax,
 * the year's result, and the next year's disposition of it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('closing_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->string('key', 40);
            $table->foreignId('checked_by')->constrained('users');
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'key']);
        });

        Schema::create('year_end_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->string('type', 20);
            $table->bigInteger('amount_ore');
            $table->bigInteger('dividend_ore')->default(0);
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['fiscal_year_id', 'type']);
        });

        // A closing entry is part of the books: it is never changed or removed.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION year_end_entries_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Year-end entries cannot be changed or deleted.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER year_end_entries_append_only
                BEFORE UPDATE OR DELETE ON year_end_entries
                FOR EACH ROW EXECUTE FUNCTION year_end_entries_append_only();
        SQL);
    }
};
