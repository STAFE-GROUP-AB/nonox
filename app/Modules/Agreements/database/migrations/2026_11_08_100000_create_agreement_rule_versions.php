<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NONOX Avtal (ADR-033 §2): collective agreement facts as versioned,
 * sourced data, global like payroll_rule_versions. A version counts in a
 * payroll run only once a named person has verified it, for the whole
 * installation (verified_by/verified_at) or for one company
 * (agreement_rule_verifications). A verified version never changes except
 * valid_to (an agreement that runs on after it expires); a correction is a
 * new version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreement_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->string('agreement_key', 60);
            $table->string('variant', 20);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->jsonb('rules');
            $table->jsonb('sources');
            $table->char('checksum', 64);
            $table->string('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['agreement_key', 'variant', 'valid_from']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION agreement_rule_versions_protect_verified() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.verified_at IS NOT NULL THEN
                        RAISE EXCEPTION 'verified agreement version % % % cannot be deleted', OLD.agreement_key, OLD.variant, OLD.valid_from
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.verified_at IS NOT NULL
                   AND (to_jsonb(NEW) - 'valid_to' - 'updated_at') <> (to_jsonb(OLD) - 'valid_to' - 'updated_at') THEN
                    RAISE EXCEPTION 'verified agreement version % % % cannot be changed; add a new version', OLD.agreement_key, OLD.variant, OLD.valid_from
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER agreement_rule_versions_protect_verified
                BEFORE UPDATE OR DELETE ON agreement_rule_versions
                FOR EACH ROW EXECUTE FUNCTION agreement_rule_versions_protect_verified();
            SQL);
    }
};
