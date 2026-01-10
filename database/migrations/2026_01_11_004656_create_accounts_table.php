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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('account_number', 10)->comment('Kontonummer (BAS)');
            $table->string('name');
            $table->enum('type', ['asset', 'liability', 'equity', 'income', 'expense'])->comment('Kontotyp');
            $table->boolean('active')->default(true);
            $table->timestamps();
            
            $table->unique(['company_id', 'account_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
