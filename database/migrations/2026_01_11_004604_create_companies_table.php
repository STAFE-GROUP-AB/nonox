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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('org_number')->unique()->comment('Organisationsnummer');
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('vat_number')->nullable()->comment('Momsnummer');
            $table->date('fiscal_year_start');
            $table->date('fiscal_year_end');
            $table->string('base_currency', 3)->default('SEK');
            $table->string('bankgiro')->nullable();
            $table->string('plusgiro')->nullable();
            $table->string('swish_number')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
