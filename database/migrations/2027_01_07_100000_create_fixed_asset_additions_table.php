<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One investment built from several purchases: a later voucher row or
 * supplier invoice added to an existing asset raises its acquisition value
 * (ADR-010, fixed asset additions). Each addition keeps the link to what
 * booked it and the last depreciation month booked when it was added, so
 * the added amount is written off prospectively. Append-only, like the
 * booked depreciations: an addition belongs to a posted voucher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_additions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedBigInteger('fixed_asset_id');
            $table->bigInteger('amount_ore');
            $table->date('added_on');
            $table->char('booked_through', 7)->nullable();
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->foreignId('voucher_row_id')->nullable()->unique()->constrained('voucher_rows');
            $table->foreignUuid('supplier_invoice_id')->nullable()->constrained('supplier_invoices');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('fixed_asset_id');
            $table->index('voucher_id');
            // The asset must belong to the same company: the foreign key includes company_id.
            $table->foreign(['fixed_asset_id', 'company_id'])->references(['id', 'company_id'])->on('fixed_assets');
        });

        DB::statement('ALTER TABLE fixed_asset_additions ADD CONSTRAINT fixed_asset_additions_amount_positive CHECK (amount_ore > 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fixed_asset_additions_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Fixed asset additions cannot be changed or deleted.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fixed_asset_additions_append_only
                BEFORE UPDATE OR DELETE ON fixed_asset_additions
                FOR EACH ROW EXECUTE FUNCTION fixed_asset_additions_append_only();
        SQL);
    }
};
