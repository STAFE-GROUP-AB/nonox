<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ADR-082 P5: how a price agreement became active (signed at a link, or by
 * hand with a reason, for example a contract signed on paper) and who did
 * it. Additive columns only.
 *
 * Deletion: P1 left price_agreements and price_agreement_items ReadWrite in
 * DatabaseGrants, because a draft may be deleted. A row-level rule cannot be
 * a grant, so a trigger refuses deleting an agreement that has left DRAFT
 * (sent, active, ended, terminated or superseded) and the items of such an
 * agreement. The company cascade is unchanged: when the company itself is
 * gone the rows go with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_agreements', function (Blueprint $table): void {
            $table->string('activation_method', 10)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users');
            $table->string('activation_reason', 500)->nullable();
        });

        DB::statement("ALTER TABLE price_agreements ADD CONSTRAINT price_agreements_activation_check CHECK (activation_method IS NULL OR activation_method IN ('SIGNED', 'MANUAL'))");
        DB::statement("ALTER TABLE price_agreements ADD CONSTRAINT price_agreements_manual_reason_check CHECK (activation_method IS DISTINCT FROM 'MANUAL' OR (activation_reason IS NOT NULL AND length(btrim(activation_reason)) > 0))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION price_agreements_protect_delete() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF pg_trigger_depth() > 1 AND NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id) THEN
                    RETURN OLD;
                END IF;

                IF OLD.status <> 'DRAFT' THEN
                    RAISE EXCEPTION 'price agreement % is no longer a draft and cannot be deleted', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN OLD;
            END;
            $$;

            CREATE TRIGGER price_agreements_protect_delete
                BEFORE DELETE ON price_agreements
                FOR EACH ROW EXECUTE FUNCTION price_agreements_protect_delete();

            CREATE OR REPLACE FUNCTION price_agreement_items_protect_delete() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF pg_trigger_depth() > 1 AND NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id) THEN
                    RETURN OLD;
                END IF;

                IF EXISTS (SELECT 1 FROM price_agreements WHERE id = OLD.price_agreement_id AND status <> 'DRAFT') THEN
                    RAISE EXCEPTION 'the terms of price agreement % are no longer a draft and cannot be deleted', OLD.price_agreement_id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN OLD;
            END;
            $$;

            CREATE TRIGGER price_agreement_items_protect_delete
                BEFORE DELETE ON price_agreement_items
                FOR EACH ROW EXECUTE FUNCTION price_agreement_items_protect_delete();
        SQL);
    }
};
