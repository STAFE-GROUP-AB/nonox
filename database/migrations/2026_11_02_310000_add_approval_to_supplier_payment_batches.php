<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A payment file is approved (payments:approve) before it can be downloaded
 * for the bank or confirmed as paid (AGENTS.md §4). The approval is set once,
 * while the batch is waiting for the bank, and never changes afterwards.
 * Batches made before this step keep no approval: confirmed and cancelled
 * ones are history, open ones must be approved like new ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payment_batches', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
        });

        DB::unprepared(<<<'SQL'
            -- A batch's file and contents never change; only its approval (once) and status move on.
            CREATE OR REPLACE FUNCTION supplier_payment_batches_protect() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'payment batch % cannot be deleted', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF (to_jsonb(NEW) - 'status' - 'approved_at' - 'approved_by' - 'confirmed_at' - 'confirmed_by' - 'cancelled_at' - 'cancelled_by' - 'updated_at')
                     <> (to_jsonb(OLD) - 'status' - 'approved_at' - 'approved_by' - 'confirmed_at' - 'confirmed_by' - 'cancelled_at' - 'cancelled_by' - 'updated_at') THEN
                    RAISE EXCEPTION 'payment batch % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF (NEW.approved_at IS DISTINCT FROM OLD.approved_at OR NEW.approved_by IS DISTINCT FROM OLD.approved_by)
                   AND (OLD.approved_at IS NOT NULL OR OLD.status <> 'CREATED' OR NEW.approved_at IS NULL OR NEW.approved_by IS NULL) THEN
                    RAISE EXCEPTION 'payment batch % approval cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.status <> 'CREATED' AND NEW.status <> OLD.status THEN
                    RAISE EXCEPTION 'payment batch % is already %', OLD.id, OLD.status
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.status = 'CREATED' AND NEW.status = 'CONFIRMED' AND NEW.approved_at IS NULL THEN
                    RAISE EXCEPTION 'payment batch % is not approved', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);
    }
};
