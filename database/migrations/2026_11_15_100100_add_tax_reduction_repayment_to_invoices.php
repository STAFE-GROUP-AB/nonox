<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A credit of a ROT or RUT invoice whose deduction Skatteverket has already
 * paid (#49): that part is a debt to Skatteverket (lag (2009:194) 13-14 §§),
 * booked on 2890 instead of leaving 1513 negative. The credit invoice keeps
 * the amount that became a debt when it was issued, and how much of it has
 * been paid back; only the latter changes afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->bigInteger('tax_reduction_repayable_ore')->default(0);
            $table->bigInteger('tax_reduction_repaid_ore')->default(0);
        });

        DB::unprepared(<<<'SQL'
            -- An issued invoice keeps its contents: only status, payments and
            -- credits (in kronor and in its own currency), Skatteverket's
            -- ROT/RUT payment and its repayment, and sending may change.
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
                   AND (to_jsonb(NEW) - 'status' - 'paid_ore' - 'credited_ore' - 'foreign_paid_minor' - 'tax_reduction_paid_ore' - 'tax_reduction_repaid_ore' - 'sent_at' - 'sent_to' - 'updated_at')
                     <> (to_jsonb(OLD) - 'status' - 'paid_ore' - 'credited_ore' - 'foreign_paid_minor' - 'tax_reduction_paid_ore' - 'tax_reduction_repaid_ore' - 'sent_at' - 'sent_to' - 'updated_at') THEN
                    RAISE EXCEPTION 'issued invoice % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
    }
};
