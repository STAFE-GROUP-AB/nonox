<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * NONOX Banking (ADR-034): a company's consents to read its bank accounts
 * through an open-banking provider, the accounts in them with the ledger
 * account each one feeds, and the transactions and balances fetched. What
 * the bank said is never changed; a transaction only gains its match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('institution_name', 255);
            $table->char('institution_country', 2);
            $table->string('psu_type', 20);
            // The provider's session id, encrypted (`encrypted` cast); cleared when the consent ends.
            $table->text('session_id')->nullable();
            $table->string('status', 20);
            $table->timestampTz('valid_until')->nullable();
            $table->foreignId('connected_by')->constrained('users');
            $table->timestampTz('connected_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_fetched_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestampTz('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('bank_feed_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('bank_connection_id')->constrained('bank_connections');
            // The provider's id for the account within this consent, encrypted.
            $table->text('provider_account_id');
            // The provider's stable hash of the account's identification, the same across consents.
            $table->string('identification_hash', 255);
            $table->text('iban')->nullable();
            $table->string('iban_last4', 4)->nullable();
            $table->string('name', 255)->nullable();
            $table->char('currency', 3);
            $table->foreignId('account_id')->nullable()->constrained('accounts');
            $table->boolean('is_active')->default(true);
            $table->date('fetched_through')->nullable();
            $table->timestampTz('last_fetched_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'identification_hash']);
            $table->index(['company_id', 'account_id']);
        });

        Schema::create('bank_feed_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('bank_feed_account_id')->constrained('bank_feed_accounts');
            // A hash of the bank's id for the transaction, else of what it says (ADR-034).
            $table->char('fingerprint', 64);
            $table->string('provider_transaction_id', 255)->nullable();
            $table->string('entry_reference', 255)->nullable();
            $table->date('booking_date');
            $table->date('value_date')->nullable();
            // Signed: money in is positive, money out negative.
            $table->bigInteger('amount_ore');
            $table->char('currency', 3);
            $table->string('counterparty', 255)->nullable();
            $table->string('message', 500)->nullable();
            $table->string('reference', 64)->nullable();
            $table->foreignId('voucher_row_id')->nullable()->constrained('voucher_rows');
            $table->foreignId('matched_by')->nullable()->constrained('users');
            $table->timestampTz('matched_at')->nullable();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions');
            $table->timestamps();

            $table->unique(['company_id', 'bank_feed_account_id', 'fingerprint']);
            $table->index(['company_id', 'bank_feed_account_id', 'booking_date']);
            $table->index(['company_id', 'voucher_row_id']);
        });

        Schema::create('bank_feed_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('bank_feed_account_id')->constrained('bank_feed_accounts');
            $table->date('balance_date');
            $table->string('balance_type', 10);
            $table->bigInteger('amount_ore');
            $table->char('currency', 3);
            $table->timestampTz('fetched_at');
            $table->timestamps();

            $table->unique(['bank_feed_account_id', 'balance_date', 'balance_type']);
            $table->index(['company_id', 'bank_feed_account_id']);
        });

        DB::unprepared(<<<'SQL'
            -- A fetched transaction keeps what the bank said; it only gains or loses its match and the incoming payment it fed.
            CREATE OR REPLACE FUNCTION bank_feed_transactions_protect() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'bank feed transactions cannot be deleted'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF (to_jsonb(NEW) - 'voucher_row_id' - 'matched_by' - 'matched_at' - 'bank_transaction_id' - 'updated_at')
                   <> (to_jsonb(OLD) - 'voucher_row_id' - 'matched_by' - 'matched_at' - 'bank_transaction_id' - 'updated_at') THEN
                    RAISE EXCEPTION 'bank feed transaction % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER bank_feed_transactions_protect
                BEFORE UPDATE OR DELETE ON bank_feed_transactions
                FOR EACH ROW EXECUTE FUNCTION bank_feed_transactions_protect();
            SQL);
    }
};
