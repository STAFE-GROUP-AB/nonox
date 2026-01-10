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
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('voucher_number')->comment('Verifikationsnummer');
            $table->date('date')->comment('Verifikationsdatum');
            $table->text('description')->comment('Beskrivning');
            $table->json('lines')->comment('Verifikationsrader (konto, debit, credit). JSON används för flexibilitet.');  
            $table->string('source_type')->nullable()->comment('Källtyp (invoice, payment, manual)');
            $table->unsignedBigInteger('source_id')->nullable()->comment('Käll-ID');
            $table->boolean('immutable')->default(false)->comment('Kan ej ändras');
            $table->timestamps();
            
            $table->unique(['company_id', 'voucher_number']);
            $table->index(['source_type', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
