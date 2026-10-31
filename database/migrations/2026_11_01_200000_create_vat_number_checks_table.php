<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Checks of buyers' VAT numbers in the EU's VIES register: what was asked,
 * what VIES answered and its consultation number. Evidence that the buyer
 * was a registered business when the VAT-free sale was made, so it never
 * changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_number_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('vat_number', 20);
            $table->boolean('valid');
            $table->string('name')->nullable();
            $table->text('address')->nullable();
            $table->string('request_identifier')->nullable();
            $table->timestamp('checked_at');
            $table->foreignId('checked_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'vat_number', 'checked_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION vat_number_checks_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND OLD.customer_id IS NOT NULL AND NEW.customer_id IS NULL
                    AND NEW.vat_number = OLD.vat_number AND NEW.valid = OLD.valid THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'VAT number checks cannot be changed or deleted.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER vat_number_checks_append_only
                BEFORE UPDATE OR DELETE ON vat_number_checks
                FOR EACH ROW EXECUTE FUNCTION vat_number_checks_append_only();
        SQL);
    }
};
