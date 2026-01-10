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
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['customer', 'supplier'])->comment('Kund eller leverantör');
            $table->string('name');
            $table->string('org_number')->nullable()->comment('Organisationsnummer');
            $table->string('personal_number')->nullable()->comment('Personnummer');
            $table->string('vat_number')->nullable()->comment('Momsnummer');
            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->integer('payment_terms')->default(30)->comment('Betalningsvillkor i dagar');
            $table->string('reference')->nullable()->comment('Referens');
            $table->boolean('active')->default(true);
            $table->timestamps();
            
            $table->index(['company_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
