<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('item_number')->comment('Artikelnummer');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit')->default('st')->comment('Enhet (st, tim, kg, etc.)');
            $table->decimal('price_ex_vat', 10, 2)->comment('Pris exkl. moms');
            $table->decimal('vat_rate', 5, 2)->comment('Momssats (25, 12, 6, 0)');
            $table->string('income_account')->nullable()->comment('BAS-konto för intäkt');
            $table->boolean('active')->default(true);
            $table->timestamps();
            
            $table->unique(['company_id', 'item_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
