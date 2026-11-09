-- NONOX database roles (ADR-079 §6, build step 2).
--
-- Creates the three roles and the privileges that are not per table:
--   nonox_owner     owns every table, trigger and function; used only for migrations.
--   nonox_app       the runtime role: per-table grants (grants.sql), sequences, nothing else.
--   nonox_readonly  SELECT only, for reports and backups.
--
-- Run as a superuser (or a role with CREATEROLE that may grant on the schema), connected to
-- the NONOX database:
--   psql -d <database> -v ON_ERROR_STOP=1 -f database/roles/roles.sql
--
-- Idempotent: run it any number of times. It changes roles and privileges only, never data,
-- and it never gives a role LOGIN or a password. Do that yourself, with \password in psql,
-- so that no password is ever written in a file:
--   ALTER ROLE nonox_owner LOGIN;  \password nonox_owner
--   ALTER ROLE nonox_app LOGIN;    \password nonox_app
-- Then transfer the existing objects to nonox_owner (docs/drift/databasroller.md) and apply
-- the per-table grants (database/roles/grants.sql, or `php artisan nonox:db-grants`).

DO $roles$
DECLARE
    role_name text;
BEGIN
    FOREACH role_name IN ARRAY ARRAY['nonox_owner', 'nonox_app', 'nonox_readonly']
    LOOP
        IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = role_name) THEN
            EXECUTE format('CREATE ROLE %I NOLOGIN', role_name);
        END IF;

        -- Whatever the role was before: never a superuser, never able to create roles or
        -- databases, replicate or bypass row-level security. LOGIN and passwords are left alone.
        -- Only altered when something differs, so a second run changes nothing.
        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = role_name
                   AND (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls)) THEN
            EXECUTE format('ALTER ROLE %I NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS', role_name);
        END IF;
    END LOOP;

    -- The runtime and reader roles must not inherit the owner's rights through membership.
    IF pg_has_role('nonox_app', 'nonox_owner', 'MEMBER') THEN
        RAISE EXCEPTION 'nonox_app is a member of nonox_owner; revoke that membership first';
    END IF;

    IF pg_has_role('nonox_readonly', 'nonox_owner', 'MEMBER') THEN
        RAISE EXCEPTION 'nonox_readonly is a member of nonox_owner; revoke that membership first';
    END IF;

    EXECUTE format('GRANT CONNECT ON DATABASE %I TO nonox_owner, nonox_app, nonox_readonly', current_database());

    -- No temporary tables: a temporary table can shadow a real one for code that looks tables up by
    -- name. The trigger functions are pinned against that (search_path = public, pg_temp); this
    -- takes the tool away as well. NONOX itself never creates temporary tables.
    EXECUTE format('REVOKE TEMPORARY ON DATABASE %I FROM PUBLIC', current_database());
    EXECUTE format('REVOKE TEMPORARY ON DATABASE %I FROM nonox_app, nonox_readonly', current_database());

    -- No large objects: NONOX stores files outside the database, and large objects are a way to
    -- store data no grant decision covers. The functions are per database, so this is too.
    REVOKE EXECUTE ON FUNCTION lo_create(oid), lo_creat(integer), lo_from_bytea(oid, bytea), lo_import(text), lo_import(text, oid) FROM PUBLIC;

    -- The predefined roles that reach the server's files or every table are never for these roles.
    IF pg_has_role('nonox_app', 'pg_execute_server_program', 'MEMBER')
       OR pg_has_role('nonox_app', 'pg_write_server_files', 'MEMBER')
       OR pg_has_role('nonox_app', 'pg_read_server_files', 'MEMBER')
       OR pg_has_role('nonox_app', 'pg_write_all_data', 'MEMBER') THEN
        RAISE EXCEPTION 'nonox_app is a member of a predefined role that reaches server files or all data; revoke that first';
    END IF;

    -- Only the owner may create objects in the schema; nobody else, PUBLIC included.
    EXECUTE format('REVOKE CREATE ON SCHEMA %I FROM PUBLIC', current_schema());
    EXECUTE format('REVOKE CREATE ON SCHEMA %I FROM nonox_app, nonox_readonly', current_schema());
    EXECUTE format('GRANT USAGE, CREATE ON SCHEMA %I TO nonox_owner', current_schema());
    EXECUTE format('GRANT USAGE ON SCHEMA %I TO nonox_app, nonox_readonly', current_schema());

    -- Never the right to change a table's triggers or to empty it, whatever was granted before.
    EXECUTE format('REVOKE TRUNCATE, REFERENCES, TRIGGER ON ALL TABLES IN SCHEMA %I FROM nonox_app, nonox_readonly', current_schema());

    -- Sequences: the app draws ids, the reader reads.
    EXECUTE format('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA %I TO nonox_app', current_schema());
    EXECUTE format('GRANT SELECT ON ALL SEQUENCES IN SCHEMA %I TO nonox_readonly', current_schema());
    EXECUTE format('GRANT SELECT ON ALL TABLES IN SCHEMA %I TO nonox_readonly', current_schema());

    -- Objects the owner creates later: sequences usable by the app, everything readable by the reader.
    -- Table privileges for the app are NOT defaulted: each table gets its own decision (grants.sql).
    EXECUTE format('ALTER DEFAULT PRIVILEGES FOR ROLE nonox_owner IN SCHEMA %I GRANT USAGE, SELECT ON SEQUENCES TO nonox_app', current_schema());
    EXECUTE format('ALTER DEFAULT PRIVILEGES FOR ROLE nonox_owner IN SCHEMA %I GRANT SELECT ON SEQUENCES TO nonox_readonly', current_schema());
    EXECUTE format('ALTER DEFAULT PRIVILEGES FOR ROLE nonox_owner IN SCHEMA %I GRANT SELECT ON TABLES TO nonox_readonly', current_schema());
END
$roles$;
