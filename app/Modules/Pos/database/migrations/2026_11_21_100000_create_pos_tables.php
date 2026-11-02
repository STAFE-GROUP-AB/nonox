<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Kassakopplingar (ADR-042): a company's connections to its tills, the
 * links between articles and the till's products, every sale the till
 * reported (kept as it came, the basis for the daily voucher), the stock
 * changes sent back to the till and a log per connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('status', 20);
            // The till's own id for the merchant (organisation uuid, realm).
            $table->string('external_org_id', 255)->nullable();
            $table->string('display_name', 255)->nullable();
            // Tokens, keys and signing secrets, encrypted (`encrypted:array` cast); cleared on disconnect.
            $table->text('credentials')->nullable();
            // Opaque id in the webhook address; identifies the connection without revealing the company.
            $table->string('webhook_token', 64)->unique();
            $table->jsonb('settings')->default('{}');
            $table->foreignId('location_id')->nullable()->constrained('stock_locations');
            $table->timestampTz('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->foreignId('connected_by')->constrained('users');
            $table->timestampTz('connected_at');
            $table->foreignId('disconnected_by')->nullable()->constrained('users');
            $table->timestampTz('disconnected_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'provider', 'status']);
        });

        // One merchant account can only feed one company at a time.
        DB::statement("CREATE UNIQUE INDEX pos_connections_live_org_unique ON pos_connections (provider, external_org_id) WHERE status <> 'DISCONNECTED' AND external_org_id IS NOT NULL");

        Schema::create('pos_product_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('pos_connection_id')->constrained('pos_connections');
            $table->foreignId('article_id')->constrained('articles');
            $table->string('external_product_id', 100);
            $table->string('external_variant_id', 100)->nullable();
            // What was last sent, so an unchanged article is not sent again.
            $table->char('pushed_hash', 64)->nullable();
            $table->boolean('stock_tracking')->default(false);
            $table->timestampTz('synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->unique(['pos_connection_id', 'article_id']);
            $table->unique(['pos_connection_id', 'external_product_id']);
        });

        Schema::create('pos_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('pos_connection_id')->constrained('pos_connections');
            $table->string('provider', 20);
            // The till's id for the purchase or receipt: the idempotency key.
            $table->string('external_id', 150);
            $table->string('source', 20);
            $table->boolean('is_refund')->default(false);
            $table->timestampTz('sold_at');
            // The day in Europe/Stockholm the sale belongs to.
            $table->date('business_date');
            $table->char('currency', 3);
            $table->bigInteger('total_ore');
            $table->bigInteger('vat_ore');
            $table->bigInteger('tips_ore')->default(0);
            // Per VAT rate in basis points: net and VAT in öre.
            $table->jsonb('vat_totals');
            // Per payment method: öre.
            $table->jsonb('payments');
            $table->jsonb('warnings')->default('[]');
            $table->jsonb('payload');
            $table->char('payload_hash', 64);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            // The daily draft voucher it went into (ADR-042).
            $table->uuid('voucher_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'provider', 'external_id']);
            $table->index(['company_id', 'business_date']);
            $table->foreign('voucher_id')->references('id')->on('vouchers')->nullOnDelete();
        });

        Schema::create('pos_sale_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('pos_sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->string('external_product_id', 100)->nullable();
            $table->string('external_variant_id', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('name', 255);
            // Signed thousandths: positive sold, negative returned.
            $table->bigInteger('quantity_milli');
            $table->bigInteger('amount_ore')->default(0);
            $table->integer('vat_rate')->nullable();
            // MATCHED, UNMATCHED, SKIPPED (no goods: a custom amount or a gift card).
            $table->string('status', 20);
            $table->foreignId('article_id')->nullable()->constrained('articles');
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements');
            $table->timestamps();

            $table->unique(['pos_sale_id', 'line_no']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('pos_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('pos_connection_id')->constrained('pos_connections');
            $table->string('external_id', 150);
            $table->string('type', 40);
            $table->date('business_date');
            // The fee charged: positive costs, negative refunded.
            $table->bigInteger('amount_ore');
            $table->jsonb('payload');
            $table->uuid('voucher_id')->nullable();
            $table->timestamps();

            $table->unique(['pos_connection_id', 'external_id', 'type']);
            $table->foreign('voucher_id')->references('id')->on('vouchers')->nullOnDelete();
        });

        Schema::create('pos_stock_pushes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('pos_connection_id')->constrained('pos_connections');
            $table->foreignId('stock_movement_id')->constrained('stock_movements');
            $table->foreignId('article_id')->constrained('articles');
            $table->bigInteger('delta_milli');
            // PENDING, SENT, FAILED, SKIPPED.
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            // Sent to the till with the change, the same on every retry.
            $table->uuid('external_uuid');
            $table->string('last_error', 500)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['pos_connection_id', 'stock_movement_id']);
            $table->index(['pos_connection_id', 'status']);
        });

        Schema::create('pos_webhook_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('pos_connection_id')->constrained('pos_connections');
            $table->string('message_id', 150);
            $table->string('event_name', 100);
            $table->text('body');
            $table->timestampTz('processed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->unique(['pos_connection_id', 'message_id']);
        });

        Schema::create('pos_log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('pos_connection_id')->constrained('pos_connections');
            // info, warning, error.
            $table->string('level', 10);
            $table->string('message', 500);
            $table->jsonb('context')->default('{}');
            $table->timestampTz('created_at');

            $table->index(['pos_connection_id', 'created_at']);
        });
    }
};
