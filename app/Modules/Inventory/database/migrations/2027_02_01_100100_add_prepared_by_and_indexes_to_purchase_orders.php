<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-081 E10, review fixes: who last saved a purchase order (prepared_by), so
 * the segregation rule keeps both its creator and its last editor from approving
 * it, and indexes on the foreign keys the first migration added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('prepared_by')->nullable()->constrained('users');
            $table->index('approval_request_id');
        });

        Schema::table('purchase_order_rows', function (Blueprint $table) {
            $table->index('account_id');
            $table->index('cost_center_id');
            $table->index('project_id');
        });

        Schema::table('purchase_order_versions', function (Blueprint $table) {
            $table->index('approval_request_id');
        });
    }
};
