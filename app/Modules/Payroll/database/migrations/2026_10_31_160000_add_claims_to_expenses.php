<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outlays an employee claims on Min sida: what it was, who asked, the
 * decision and the voucher that booked it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('description')->nullable();
            $table->string('receipt_name')->nullable();
            $table->string('receipt_mime', 100)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
        });
    }
};
