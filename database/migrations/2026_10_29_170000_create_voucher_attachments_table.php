<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting documents (underlag) for vouchers, AGENTS.md §6.4. An attachment
 * is never changed. It can be removed only while its voucher is a draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'voucher_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION voucher_attachments_protect() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'voucher attachment % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF EXISTS (SELECT 1 FROM vouchers WHERE id = OLD.voucher_id AND status = 'POSTED') THEN
                    RAISE EXCEPTION 'attachments of posted voucher % cannot be removed', OLD.voucher_id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN OLD;
            END;
            $$;

            CREATE TRIGGER voucher_attachments_protect
                BEFORE UPDATE OR DELETE ON voucher_attachments
                FOR EACH ROW EXECUTE FUNCTION voucher_attachments_protect();

            CREATE TRIGGER voucher_attachments_no_truncate
                BEFORE TRUNCATE ON voucher_attachments
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_no_truncate();
            SQL);
    }
};
