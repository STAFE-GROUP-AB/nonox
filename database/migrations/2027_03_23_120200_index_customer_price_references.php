<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Price lists (ADR-082 P1, review finding): the customers of a price group or
 * a price list are looked up by those columns ("move 200 customers to a new
 * list"), so each gets an index leading with the company. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->index(['company_id', 'price_group_id']);
            $table->index(['company_id', 'price_list_id']);
        });
    }
};
