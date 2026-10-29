<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offerter and ordrar (AGENTS.md §7 Phase 2): a quote becomes an order, and
 * an order becomes an invoice. Neither is bookkeeping; nothing is posted
 * until the invoice is issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->enum('kind', ['QUOTE', 'ORDER']);
            $table->unsignedInteger('number');
            $table->foreignId('customer_id')->constrained('customers');
            $table->enum('status', ['OPEN', 'ACCEPTED', 'INVOICED', 'CANCELLED'])->default('OPEN');
            $table->date('document_date');
            // A quote is valid until this date; for an order it is the delivery date.
            $table->date('until_date')->nullable();
            $table->string('your_reference')->nullable();
            $table->string('our_reference')->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('net_ore')->default(0);
            $table->bigInteger('vat_ore')->default(0);
            $table->bigInteger('rounding_ore')->default(0);
            $table->bigInteger('total_ore')->default(0);
            $table->uuid('from_quote_id')->nullable();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices');
            $table->timestamp('sent_at')->nullable();
            $table->string('sent_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'kind', 'number']);
            $table->index(['company_id', 'kind', 'status']);
        });

        Schema::table('sales_documents', function (Blueprint $table) {
            $table->foreign('from_quote_id')->references('id')->on('sales_documents');
        });

        Schema::create('sales_document_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('sales_document_id')->constrained('sales_documents')->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles');
            $table->string('description', 255);
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 10)->default('st');
            $table->bigInteger('unit_price_ore');
            $table->unsignedSmallInteger('discount_bp')->default(0);
            $table->unsignedSmallInteger('vat_rate');
            $table->foreignId('account_id')->constrained('accounts');
            $table->bigInteger('net_ore');
            $table->unsignedSmallInteger('position');
            $table->timestamps();
        });
    }
};
