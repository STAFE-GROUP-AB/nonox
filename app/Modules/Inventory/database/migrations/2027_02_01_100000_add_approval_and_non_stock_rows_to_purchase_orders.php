<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inköpsorder through the approval engine, and rows without an article
 * (ADR-081 gap 3, E10).
 *
 * Approval: an order carries an approval round that is raised when its rows
 * change or a new version is made; the approval engine's subject is the order
 * and the round, so every round has its own request. An order is approved for
 * its round when approval_request_id is set. The version that is sent keeps
 * who approved it.
 *
 * Non-stock rows: purchase_order_rows.article_id is loosened to NULL (the
 * additive choice: no data moves, no existing row changes) and the row gets an
 * account, an optional cost centre and project, and a "delivery confirmed"
 * mark instead of a stock receipt. A row without an article must name an
 * account, so it can be booked when the supplier invoice arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE purchase_order_rows ALTER COLUMN article_id DROP NOT NULL');

        Schema::table('purchase_order_rows', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->constrained('accounts');
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers');
            $table->foreignId('project_id')->nullable()->constrained('projects');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
        });

        DB::statement('ALTER TABLE purchase_order_rows ADD CONSTRAINT purchase_order_rows_article_or_account CHECK (article_id IS NOT NULL OR account_id IS NOT NULL)');

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('approval_round')->default(1);
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
        });

        Schema::table('purchase_order_versions', function (Blueprint $table) {
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
        });
    }
};
