<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-074 §5: a ROT or RUT invoice records the hours worked and the kind of
 * work, which the claim to Skatteverket needs (lag (2009:194) 9 § p. 4,
 * AO-R14). Both are part of the issued invoice's content: the existing
 * invoices_protect_issued trigger compares every column that is not listed
 * as mutable, so the new columns are protected once the invoice is issued.
 *
 * Several ROT/RUT buyers on one invoice (ADR-074 O6), for example two
 * spouses: each buyer has a share of the labour and an own deduction within
 * the buyer's own yearly ceiling. The rows follow the invoice: they cannot
 * change once it is issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->bigInteger('labour_hours_milli')->nullable();
            $table->string('work_description', 255)->nullable();
        });

        Schema::create('invoice_tax_reduction_buyers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers');
            $table->unsignedSmallInteger('share_bp');
            $table->bigInteger('deduction_ore');
            $table->timestamps();
            $table->unique(['invoice_id', 'customer_id']);
            $table->index(['company_id', 'customer_id']);
        });

        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_labour_hours_not_negative CHECK (labour_hours_milli IS NULL OR labour_hours_milli >= 0 OR kind = \'CREDIT\')');
        DB::statement('ALTER TABLE invoice_tax_reduction_buyers ADD CONSTRAINT invoice_tax_reduction_buyers_share CHECK (share_bp > 0 AND share_bp <= 10000)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoice_tax_reduction_buyers_protect_issued() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM invoices WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.invoice_id ELSE NEW.invoice_id END AND status <> 'DRAFT') THEN
                    RAISE EXCEPTION 'buyers of issued invoice cannot be changed'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$;

            CREATE TRIGGER invoice_tax_reduction_buyers_protect_issued
                BEFORE INSERT OR UPDATE OR DELETE ON invoice_tax_reduction_buyers
                FOR EACH ROW EXECUTE FUNCTION invoice_tax_reduction_buyers_protect_issued();
            SQL);
    }
};
