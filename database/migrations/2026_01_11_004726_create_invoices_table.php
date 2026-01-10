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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('parties');
            $table->foreignId('source_order_id')->nullable()->constrained('orders');
            $table->string('invoice_number')->comment('Fakturanummer');
            $table->date('issue_date')->comment('Fakturadatum');
            $table->date('due_date')->comment('Förfallodatum');
            $table->string('ocr_reference')->nullable()->comment('OCR-referens');
            $table->json('rows')->comment('Fakturarader med artiklar');
            $table->json('vat_summary')->comment('Momssammanställning per momssats');
            $table->decimal('total_ex_vat', 12, 2)->comment('Totalt exkl. moms');
            $table->decimal('total_vat', 12, 2)->comment('Total moms');
            $table->decimal('total_incl_vat', 12, 2)->comment('Totalt inkl. moms');
            $table->enum('status', ['unpaid', 'partially_paid', 'paid'])->default('unpaid');
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->unique(['company_id', 'invoice_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
