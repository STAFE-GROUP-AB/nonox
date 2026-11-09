-- Transfer NONOX's objects to nonox_owner (ADR-079 §6, build step 2).
--
-- Every table, view, sequence, function and type in the current schema that is not part of
-- an extension gets the owner nonox_owner. Use this instead of REASSIGN OWNED when the role
-- that owns the tables today also owns other databases or is a superuser such as postgres
-- (REASSIGN OWNED would then also move objects that are not NONOX's, or is refused).
--
-- Run as a superuser, after roles.sql, connected to the NONOX database:
--   psql -d <database> -v ON_ERROR_STOP=1 -f database/roles/transfer-ownership.sql
--
-- Idempotent. Changes owners only, never data. ALTER ... OWNER takes a short exclusive lock on
-- each table, so run it in a maintenance window.

DO $transfer$
DECLARE
    object record;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'nonox_owner') THEN
        RAISE EXCEPTION 'Run database/roles/roles.sql first: the role nonox_owner does not exist';
    END IF;

    FOR object IN
        SELECT c.oid, c.relkind, c.relname
        FROM pg_class c
        WHERE c.relnamespace = current_schema()::regnamespace
          AND c.relkind IN ('r', 'p', 'v', 'm', 'S', 'f')
          AND c.relowner <> 'nonox_owner'::regrole
          AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype = 'e')
          -- A sequence owned by a table column follows its table.
          AND NOT (c.relkind = 'S' AND EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype IN ('a', 'i')))
        ORDER BY c.relkind DESC, c.relname
    LOOP
        EXECUTE format(
            'ALTER %s %I OWNER TO nonox_owner',
            CASE object.relkind
                WHEN 'v' THEN 'VIEW'
                WHEN 'm' THEN 'MATERIALIZED VIEW'
                WHEN 'S' THEN 'SEQUENCE'
                WHEN 'f' THEN 'FOREIGN TABLE'
                ELSE 'TABLE'
            END,
            object.relname
        );
    END LOOP;

    FOR object IN
        SELECT p.oid
        FROM pg_proc p
        WHERE p.pronamespace = current_schema()::regnamespace
          AND p.proowner <> 'nonox_owner'::regrole
          AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_proc'::regclass AND d.objid = p.oid AND d.deptype = 'e')
    LOOP
        EXECUTE format('ALTER ROUTINE %s OWNER TO nonox_owner', object.oid::regprocedure);
    END LOOP;

    FOR object IN
        SELECT t.oid
        FROM pg_type t
        WHERE t.typnamespace = current_schema()::regnamespace
          AND t.typtype IN ('e', 'd', 'c', 'r')
          AND t.typowner <> 'nonox_owner'::regrole
          AND (t.typtype <> 'c' OR (SELECT relkind FROM pg_class WHERE oid = t.typrelid) = 'c')
          AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_type'::regclass AND d.objid = t.oid AND d.deptype = 'e')
    LOOP
        EXECUTE format('ALTER TYPE %s OWNER TO nonox_owner', object.oid::regtype);
    END LOOP;
END
$transfer$;
