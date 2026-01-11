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
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('parties');
            $table->string('quote_number')->unique()->comment('Offernummer');
            $table->date('valid_until')->comment('Giltig till');
            $table->enum('status', ['draft', 'sent', 'accepted', 'rejected'])->default('draft');
            $table->json('rows')->comment('Offertrader med artiklar');
            $table->decimal('total_ex_vat', 12, 2)->comment('Totalt exkl. moms');
            $table->decimal('total_vat', 12, 2)->comment('Total moms');
            $table->decimal('total_incl_vat', 12, 2)->comment('Totalt inkl. moms');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
