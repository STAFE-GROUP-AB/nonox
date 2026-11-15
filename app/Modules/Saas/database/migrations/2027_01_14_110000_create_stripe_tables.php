<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Stripe in NONOX Connect, step 2 of ADR-080 (§7.1–§7.5): read-only import of
 * customers and subscriptions for the recurring-revenue ledger.
 *
 * - stripe_connections: one per company and Stripe account. The restricted
 *   key and the webhook secret are encrypted by the model (encrypted cast,
 *   hidden), only the last four characters of the key are kept in clear. A
 *   Stripe account is active in at most one company, and a company has at
 *   most one active connection (partial unique indexes).
 * - stripe_objects: the raw objects as fetched (products, prices, coupons,
 *   subscriptions), a new row only when the payload's SHA-256 changes. Rows
 *   are never changed (trigger); they are pruned after a disconnect (§8).
 *   Customers are not stored raw: only what matching and the invoices need
 *   is kept on stripe_customer_links (minimisation, §8).
 * - stripe_customer_links: each Stripe customer and the NONOX customer it is
 *   linked to, if any, how, and by whom (§7.5).
 * - stripe_events: every event id seen, from the webhook or the Events list,
 *   unique per connection, so an event is handled once (§7.3, §7.4).
 * - stripe_sync_runs: the sync log shown on /aterkommande-intakter/stripe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('account_id', 64);
            $table->boolean('livemode')->default(true);
            $table->text('secret_key')->nullable();
            $table->string('key_last4', 4);
            $table->string('webhook_token', 48)->unique();
            $table->text('webhook_secret')->nullable();
            $table->unsignedBigInteger('connected_by')->nullable();
            $table->timestamp('connected_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('ended_by')->nullable();
            $table->string('end_reason', 30)->nullable();
            $table->timestamp('initial_import_done_at')->nullable();
            $table->jsonb('cursor')->nullable();
            $table->unsignedBigInteger('last_event_created')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX stripe_connections_account_active ON stripe_connections (account_id) WHERE status = 'ACTIVE'");
        DB::statement("CREATE UNIQUE INDEX stripe_connections_company_active ON stripe_connections (company_id) WHERE status = 'ACTIVE'");

        Schema::create('stripe_objects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('stripe_connections')->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('stripe_id', 255);
            $table->jsonb('payload');
            $table->char('sha256', 64);
            $table->timestamp('fetched_at');

            $table->unique(['connection_id', 'type', 'stripe_id', 'sha256']);
            $table->index(['company_id', 'type', 'stripe_id', 'id']);
        });

        Schema::create('stripe_customer_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('stripe_connections')->cascadeOnDelete();
            $table->string('stripe_customer_id', 255);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('status', 20);
            $table->string('method', 20)->nullable();
            $table->unsignedBigInteger('suggested_customer_id')->nullable();
            $table->string('suggestion_method', 20)->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            // What the matching and the pages need, as it appears in Stripe (ADR-080 §8).
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('country', 2)->nullable();
            $table->jsonb('address')->nullable();
            $table->jsonb('tax_ids')->nullable();
            $table->string('nonox_customer_number', 64)->nullable();
            $table->boolean('deleted_in_stripe')->default(false);
            $table->timestamps();

            $table->unique(['connection_id', 'stripe_customer_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'customer_id']);
        });

        Schema::create('stripe_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('stripe_connections')->cascadeOnDelete();
            $table->string('event_id', 255);
            $table->string('type', 100);
            $table->string('object_id', 255)->nullable();
            $table->unsignedBigInteger('event_created');
            $table->string('received_from', 10);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'event_id']);
            $table->index(['connection_id', 'processed_at']);
        });

        Schema::create('stripe_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('stripe_connections')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('requests')->default(0);
            $table->jsonb('counts')->nullable();
            $table->string('error', 40)->nullable();
            $table->timestamps();

            $table->index(['connection_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stripe_objects_no_update() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                RAISE EXCEPTION 'stripe_objects keeps each object as received: UPDATE is not allowed'
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER stripe_objects_no_update
                BEFORE UPDATE ON stripe_objects
                FOR EACH ROW EXECUTE FUNCTION stripe_objects_no_update();
            SQL);
    }
};
