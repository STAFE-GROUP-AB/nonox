<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned payroll rules (PAYROLL_MODULE.md B.6): rates, thresholds and
 * amounts with the dates they apply and their source. Global, not per
 * company. A rule counts only once a named person has verified it; a
 * verified version never changes, a new one is added instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->jsonb('value');
            $table->string('source_url', 500);
            $table->text('source_quote')->nullable();
            $table->string('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['key', 'valid_from']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payroll_rule_versions_protect_verified() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.verified_at IS NOT NULL THEN
                        RAISE EXCEPTION 'verified payroll rule % cannot be deleted', OLD.key
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.verified_at IS NOT NULL
                   AND (to_jsonb(NEW) - 'valid_to' - 'updated_at') <> (to_jsonb(OLD) - 'valid_to' - 'updated_at') THEN
                    RAISE EXCEPTION 'verified payroll rule % cannot be changed; add a new version', OLD.key
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payroll_rule_versions_protect_verified
                BEFORE UPDATE OR DELETE ON payroll_rule_versions
                FOR EACH ROW EXECUTE FUNCTION payroll_rule_versions_protect_verified();
            SQL);
    }
};
