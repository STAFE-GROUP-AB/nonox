<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Every version of the systemdokumentation this installation has used, kept
 * so the archive package of a fiscal year can include the version that
 * applied during the year (BFL 5 kap. 11 §; BFNAR 2013:2, comment on p. 9.1:
 * a replaced version of the systemdokumentation is kept too; #44). It
 * describes the installation, not one company, so it has no company_id.
 * Append-only, like the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_documentation_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 20)->nullable();
            $table->date('document_date')->nullable();
            $table->char('sha256', 64)->unique();
            $table->text('content');
            $table->string('program_version', 40)->nullable();
            $table->string('program_sha', 40)->nullable();
            $table->timestamp('recorded_at')->useCurrent()->index();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION system_documentation_versions_are_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'system_documentation_versions is append-only: % is not allowed', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER system_documentation_versions_no_update_or_delete
                BEFORE UPDATE OR DELETE ON system_documentation_versions
                FOR EACH ROW EXECUTE FUNCTION system_documentation_versions_are_append_only();

            CREATE TRIGGER system_documentation_versions_no_truncate
                BEFORE TRUNCATE ON system_documentation_versions
                FOR EACH STATEMENT EXECUTE FUNCTION system_documentation_versions_are_append_only();
            SQL);
    }
};
