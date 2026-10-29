<?php

declare(strict_types=1);

use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vouchers (verifikationer), AGENTS.md §6.4. Posted vouchers are immutable:
 * PostgreSQL triggers reject changes to them and their rows, and refuse to mark
 * a voucher as posted unless it balances (§1.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            // The newest hash in this year's ledger chain, updated under the
            // same row lock as the voucher numbering (ADR-007).
            $table->char('ledger_head_hash', 64)->nullable();
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->foreignId('series_id')->constrained('voucher_series');
            $table->unsignedInteger('number')->nullable();
            $table->enum('status', array_column(VoucherStatus::cases(), 'value'))->default(VoucherStatus::Draft->value);
            $table->date('voucher_date');
            $table->string('description', 255);
            $table->enum('source', array_column(VoucherSource::cases(), 'value'))->default(VoucherSource::Manual->value);
            $table->nullableMorphs('source', 'vouchers_source_index');
            $table->uuid('corrects_voucher_id')->nullable();
            $table->uuid('corrected_by_voucher_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->char('hash', 64)->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year_id', 'series_id', 'number']);
            $table->index(['company_id', 'voucher_date']);
            $table->index(['company_id', 'status']);
        });

        // Self-references are added once the primary key exists.
        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreign('corrects_voucher_id')->references('id')->on('vouchers');
            $table->foreign('corrected_by_voucher_id')->references('id')->on('vouchers');
        });

        Schema::create('voucher_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts');
            $table->bigInteger('debit_ore')->default(0);
            $table->bigInteger('credit_ore')->default(0);
            $table->string('description', 255)->nullable();
            // Dimension tables arrive in §6.5; the columns exist so rows never change shape.
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->decimal('quantity', 15, 4)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'account_id']);
        });

        Schema::create('voucher_number_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->foreignId('series_id')->constrained('voucher_series');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year_id', 'series_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE voucher_rows ADD CONSTRAINT voucher_rows_one_sided_amount
                CHECK (debit_ore >= 0 AND credit_ore >= 0 AND NOT (debit_ore > 0 AND credit_ore > 0));

            ALTER TABLE vouchers ADD CONSTRAINT vouchers_posted_has_number
                CHECK (status <> 'POSTED' OR (number IS NOT NULL AND posted_at IS NOT NULL AND hash IS NOT NULL));

            -- A posted voucher never changes. The only exception: linking the
            -- correcting voucher once (corrected_by_voucher_id from NULL).
            CREATE OR REPLACE FUNCTION vouchers_protect_posted() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'POSTED' THEN
                        RAISE EXCEPTION 'posted voucher % cannot be deleted', OLD.id
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status = 'POSTED' THEN
                    IF OLD.corrected_by_voucher_id IS NULL
                       AND NEW.corrected_by_voucher_id IS NOT NULL
                       AND (to_jsonb(NEW) - 'corrected_by_voucher_id' - 'updated_at')
                         = (to_jsonb(OLD) - 'corrected_by_voucher_id' - 'updated_at') THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'posted voucher % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;

            -- Posting is only allowed when the voucher balances and has at least
            -- two rows (§1.2: validated in code AND in the database).
            CREATE OR REPLACE FUNCTION vouchers_check_balance_on_post() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                row_count integer;
                debit_total bigint;
                credit_total bigint;
            BEGIN
                IF NEW.status = 'POSTED' AND (TG_OP = 'INSERT' OR OLD.status <> 'POSTED') THEN
                    SELECT count(*), coalesce(sum(debit_ore), 0), coalesce(sum(credit_ore), 0)
                      INTO row_count, debit_total, credit_total
                      FROM voucher_rows WHERE voucher_id = NEW.id;

                    IF row_count < 2 THEN
                        RAISE EXCEPTION 'voucher % needs at least two rows to be posted', NEW.id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF debit_total <> credit_total OR debit_total = 0 THEN
                        RAISE EXCEPTION 'voucher % does not balance (debit %, credit %)', NEW.id, debit_total, credit_total
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            -- Rows of a posted voucher can never be added, changed or removed.
            CREATE OR REPLACE FUNCTION voucher_rows_protect_posted() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE')
                   AND EXISTS (SELECT 1 FROM vouchers WHERE id = OLD.voucher_id AND status = 'POSTED') THEN
                    RAISE EXCEPTION 'rows of posted voucher % cannot be changed', OLD.voucher_id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF TG_OP IN ('INSERT', 'UPDATE')
                   AND EXISTS (SELECT 1 FROM vouchers WHERE id = NEW.voucher_id AND status = 'POSTED') THEN
                    RAISE EXCEPTION 'rows cannot be added to posted voucher %', NEW.voucher_id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION ledger_no_truncate() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '% cannot be truncated', TG_TABLE_NAME
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER vouchers_protect_posted
                BEFORE UPDATE OR DELETE ON vouchers
                FOR EACH ROW EXECUTE FUNCTION vouchers_protect_posted();

            CREATE TRIGGER vouchers_check_balance_on_post
                BEFORE INSERT OR UPDATE ON vouchers
                FOR EACH ROW EXECUTE FUNCTION vouchers_check_balance_on_post();

            CREATE TRIGGER voucher_rows_protect_posted
                BEFORE INSERT OR UPDATE OR DELETE ON voucher_rows
                FOR EACH ROW EXECUTE FUNCTION voucher_rows_protect_posted();

            CREATE TRIGGER vouchers_no_truncate
                BEFORE TRUNCATE ON vouchers
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_no_truncate();

            CREATE TRIGGER voucher_rows_no_truncate
                BEFORE TRUNCATE ON voucher_rows
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_no_truncate();
            SQL);
    }
};
