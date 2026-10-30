<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avtalsfakturering: an agreement invoices a customer the same rows every
 * month, quarter, half-year or year. Each run makes an ordinary invoice
 * (a draft, or issued and sent when the agreement says so).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('name');
            $table->unsignedTinyInteger('interval_months');
            $table->date('next_invoice_date');
            $table->date('end_date')->nullable();
            $table->enum('status', ['ACTIVE', 'PAUSED', 'ENDED'])->default('ACTIVE');
            // Issue and email the invoice at once, instead of leaving a draft to check.
            $table->boolean('send_automatically')->default(false);
            $table->string('your_reference')->nullable();
            $table->string('our_reference')->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('net_ore')->default(0);
            $table->bigInteger('total_ore')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'next_invoice_date']);
        });

        Schema::create('recurring_invoice_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('recurring_invoice_id')->constrained('recurring_invoices')->cascadeOnDelete();
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

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignUuid('recurring_invoice_id')->nullable()->constrained('recurring_invoices');
        });
    }
};
