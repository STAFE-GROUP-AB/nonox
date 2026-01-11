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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('parties');
            $table->foreignId('source_quote_id')->nullable()->constrained('quotes');
            $table->string('order_number')->unique()->comment('Ordernummer');
            $table->date('delivery_date')->nullable()->comment('Leveransdatum');
            $table->enum('status', ['open', 'delivered', 'invoiced'])->default('open');
            $table->json('rows')->comment('Orderrader med artiklar');
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
        Schema::dropIfExists('orders');
    }
};
