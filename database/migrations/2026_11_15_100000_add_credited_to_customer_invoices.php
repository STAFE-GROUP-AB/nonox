<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partial credits of customer invoices (#47): an invoice keeps how much of
 * it credit invoices have settled. paid_ore stays what is settled in all
 * (payments and credits), so remaining() is unchanged; credited_ore is the
 * part of it that came from credit invoices. Until now an invoice could
 * only be credited in full while unpaid, so the credited part of an
 * existing credited invoice is its total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->bigInteger('credited_ore')->default(0);
        });

        DB::unprepared(<<<'SQL'
            -- An issued invoice keeps its contents: only status, payments and
            -- credits (in kronor and in its own currency), Skatteverket's
            -- ROT/RUT payment and sending may change.
            CREATE OR REPLACE FUNCTION invoices_protect_issued() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'issued invoice % cannot be deleted', OLD.id
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status <> 'DRAFT'
                   AND (to_jsonb(NEW) - 'status' - 'paid_ore' - 'credited_ore' - 'foreign_paid_minor' - 'tax_reduction_paid_ore' - 'sent_at' - 'sent_to' - 'updated_at')
                     <> (to_jsonb(OLD) - 'status' - 'paid_ore' - 'credited_ore' - 'foreign_paid_minor' - 'tax_reduction_paid_ore' - 'sent_at' - 'sent_to' - 'updated_at') THEN
                    RAISE EXCEPTION 'issued invoice % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;

            UPDATE invoices SET credited_ore = total_ore WHERE status = 'CREDITED';
        SQL);
    }
};
