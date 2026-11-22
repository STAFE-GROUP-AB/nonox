<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Signing providers (ADR-046 S17, legal review F6): a run keeps the name the
 * service itself reported (CSC info) next to the company's display name, so
 * the signing certificate can show both. Set at the insert and never changed:
 * a second trigger, beside signing_provider_sessions_protect. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_provider_sessions', function (Blueprint $table) {
            $table->string('provider_service_name', 255)->nullable();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION signing_provider_sessions_protect_service_name() RETURNS trigger AS $$
            BEGIN
                IF NEW.provider_service_name IS DISTINCT FROM OLD.provider_service_name THEN
                    RAISE EXCEPTION 'A provider signing run cannot be changed (ADR-046 S17).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_provider_sessions_protect_service_name BEFORE UPDATE ON signing_provider_sessions
                FOR EACH ROW EXECUTE FUNCTION signing_provider_sessions_protect_service_name();
        SQL);
    }
};
