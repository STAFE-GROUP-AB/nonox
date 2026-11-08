<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Deferred tax items copied from the previous fiscal year (ADR-059 A.2.6,
 * S1-P5 review): the copy keeps a link to its source, and its tax value
 * must be checked by a person before the year's deferred tax can be booked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deferred_tax_items', function (Blueprint $table): void {
            $table->foreignId('copied_from_id')->nullable()->constrained('deferred_tax_items');
            $table->boolean('tax_value_needs_review')->default(false);
        });
    }
};
