<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An asset can be created when its purchase is booked: from a voucher row or
 * a supplier invoice row booked to one of the company's acquisition accounts.
 * The asset then links to the voucher and row that booked the acquisition.
 * A draft keeps its proposals until it is posted or registered; the company
 * chooses the acquisition accounts and the threshold under which no asset
 * is proposed (inventarier av mindre värde).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->foreignUuid('acquisition_voucher_id')->nullable()->constrained('vouchers');
            $table->foreignId('acquisition_voucher_row_id')->nullable()->constrained('voucher_rows');
            $table->foreignUuid('supplier_invoice_id')->nullable()->constrained('supplier_invoices');

            $table->index('acquisition_voucher_id');
            $table->index('supplier_invoice_id');
        });

        Schema::create('fixed_asset_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers')->cascadeOnDelete();
            $table->foreignUuid('supplier_invoice_id')->nullable()->constrained('supplier_invoices')->cascadeOnDelete();
            $table->integer('line');
            $table->jsonb('data');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['voucher_id', 'line']);
            $table->unique(['supplier_invoice_id', 'line']);
            $table->index('company_id');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->jsonb('asset_acquisition_accounts')->nullable();
            $table->bigInteger('asset_small_value_threshold_ore')->nullable();
        });
    }
};
