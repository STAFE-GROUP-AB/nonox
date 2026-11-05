<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A return's form is INK2, NE or INK4 (ADR-053), nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE income_tax_returns ADD CONSTRAINT income_tax_returns_form_check CHECK (form IN ('INK2', 'NE', 'INK4'))");
    }
};
