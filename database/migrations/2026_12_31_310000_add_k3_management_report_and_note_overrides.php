<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The K3 notes library and management report (ADR-059, addendum 2026-11-07,
 * A.2.7; package S1-P9).
 *
 * annual_reports gains what a person enters for a K3 annual report and the
 * books cannot tell: the management report under K3 3.7 (external factors,
 * special circumstances, changes in ownership and the owners with more than
 * ten per cent, as name plus organisation number or birth year, never a
 * personal identity number) and the facts behind the conditional notes of
 * S1-R8.1 (liabilities due after five years, off-balance arrangements, loans
 * to the board and managing director, exceptional items, the parent company,
 * related-party transactions of a public company, departures from a general
 * rule, goodwill). The K2 report ignores every new column.
 *
 * annual_report_note_overrides holds a person's own wording of a generated
 * note: the structured data is never overridden, only the text, and the
 * report marks every overridden note. generated_hash is the hash of the
 * generated text the person replaced, so a later change in the books shows
 * the override as out of date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annual_reports', function (Blueprint $table): void {
            $table->text('k3_external_factors')->nullable();
            $table->text('k3_special_circumstances')->nullable();
            $table->text('k3_ownership_changes')->nullable();
            // null: not answered; []: no owner above ten per cent.
            $table->jsonb('k3_major_owners')->nullable();
            $table->bigInteger('k3_long_term_liabilities_ore')->nullable();
            $table->text('k3_off_balance_arrangements')->nullable();
            $table->text('k3_loans_to_executives')->nullable();
            $table->jsonb('k3_exceptional_items')->nullable();
            $table->text('k3_parent_company')->nullable();
            $table->text('k3_related_party_transactions')->nullable();
            $table->text('k3_departures')->nullable();
            $table->text('k3_goodwill_note')->nullable();
            $table->unique(['id', 'company_id']);
        });

        DB::statement('ALTER TABLE annual_reports ADD CONSTRAINT annual_reports_k3_long_term_liabilities_check CHECK (k3_long_term_liabilities_ore IS NULL OR k3_long_term_liabilities_ore >= 0)');

        Schema::create('annual_report_note_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedBigInteger('annual_report_id');
            $table->string('note_key', 60);
            $table->text('text');
            $table->text('reason');
            $table->char('generated_hash', 64);
            $table->foreignId('set_by')->constrained('users');
            $table->timestamps();

            $table->unique(['annual_report_id', 'note_key']);
            $table->index('company_id');
            // The report belongs to the same company, in the database too.
            $table->foreign(['annual_report_id', 'company_id'])->references(['id', 'company_id'])->on('annual_reports')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE annual_report_note_overrides ADD CONSTRAINT annual_report_note_overrides_values_check CHECK (note_key ~ '^[a-z0-9_.]+$' AND length(btrim(text)) > 0 AND length(btrim(reason)) > 0)");
    }
};
