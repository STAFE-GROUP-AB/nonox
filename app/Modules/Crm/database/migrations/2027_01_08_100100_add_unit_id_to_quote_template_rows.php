<?php

declare(strict_types=1);

use App\Domain\Units\UnitBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Units everywhere (ADR-071 §12.9): a quote template row points at a unit
 * of the company's register. A row without a unit still means the
 * article's sales unit, so only rows with a unit text are linked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_quote_template_rows', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->constrained('units');
        });

        UnitBackfill::rows('crm_quote_template_rows', fn ($query) => $query->where(fn ($query) => $query->whereNotNull('unit')->orWhereNull('article_id')));
    }
};
