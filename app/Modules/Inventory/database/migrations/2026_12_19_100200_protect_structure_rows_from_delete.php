<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A row of a saved structure version is never deleted on its own (review
 * re-check P3): only together with its version or its company, through
 * the foreign keys' cascade, which runs inside another trigger
 * (pg_trigger_depth() > 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION structure_rows_only_cascade() RETURNS trigger AS $$
            BEGIN
                IF pg_trigger_depth() <= 1 THEN
                    RAISE EXCEPTION '% belongs to a saved structure version and is deleted only with it.', TG_TABLE_NAME;
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER bom_rows_only_cascade
                BEFORE DELETE ON bom_rows
                FOR EACH ROW EXECUTE FUNCTION structure_rows_only_cascade();
        SQL);
    }
};
