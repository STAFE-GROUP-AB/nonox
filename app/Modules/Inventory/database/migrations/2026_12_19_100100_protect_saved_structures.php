<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A saved structure version is a record (ADR-073 §2): the database refuses
 * any change to it except switching it on or off (`is_active`) and the
 * timestamp that goes with it, and any change to its rows, also from the
 * query builder and raw SQL. Deleting stays possible for the cascade when
 * a company is removed; the models refuse it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION structures_only_is_active() RETURNS trigger AS $$
            BEGIN
                IF (to_jsonb(NEW) - 'is_active' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'is_active' - 'updated_at') THEN
                    RAISE EXCEPTION '% is a saved structure version: only is_active can change.', TG_TABLE_NAME;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER bills_of_materials_only_is_active
                BEFORE UPDATE ON bills_of_materials
                FOR EACH ROW EXECUTE FUNCTION structures_only_is_active();

            CREATE OR REPLACE FUNCTION structure_rows_unchanged() RETURNS trigger AS $$
            BEGIN
                IF (to_jsonb(NEW) - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'updated_at') THEN
                    RAISE EXCEPTION '% belongs to a saved structure version and cannot change.', TG_TABLE_NAME;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER bom_rows_unchanged
                BEFORE UPDATE ON bom_rows
                FOR EACH ROW EXECUTE FUNCTION structure_rows_unchanged();
        SQL);
    }
};
