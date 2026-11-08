<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Returns never put stock back (Lead decision on review P2.5): a day whose
 * returns exceed its sales of an ingredient leaves a credit, which is
 * netted against later sales of the same ingredient at the same location.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_consumption_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations');
            $table->foreignId('article_id')->constrained('articles');
            // Thousandths still to net against later sales; never below zero.
            $table->bigInteger('quantity_milli')->default(0);
            $table->timestamps();

            $table->unique(['location_id', 'article_id']);
        });
    }
};
