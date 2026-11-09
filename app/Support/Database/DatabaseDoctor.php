<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\ConnectionInterface;

/**
 * Read-only checks of the runtime database role (ADR-079 §6): every query is a
 * SELECT against the catalogue, and the configuration is only read. Each
 * finding is a Swedish sentence for the operator; no findings means the
 * triggers hold against the running code.
 */
final class DatabaseDoctor
{
    /** Predefined roles that reach the server's files or every table. */
    private const array DANGEROUS_ROLES = ['pg_execute_server_program', 'pg_write_server_files', 'pg_read_server_files', 'pg_write_all_data'];

    /** @param array<string, mixed> $ownerConfig the pgsql_owner connection as the running code sees it */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly array $ownerConfig = [],
    ) {}

    public function role(): string
    {
        return (string) $this->connection->scalar('select current_user');
    }

    /** @return list<string> */
    public function findings(): array
    {
        $findings = [];
        $role = $this->role();
        $superuser = (bool) $this->connection->scalar('select rolsuper from pg_roles where rolname = current_user');

        if (! DatabaseRoles::enabled()) {
            $findings[] = 'Databasrollerna är inte påslagna (DB_MIGRATION_USERNAME saknas): appen och migreringarna använder samma databasanvändare. Se docs/drift/databasroller.md.';
        }

        if (DatabaseRoles::enabled() && $this->ownerPasswordInConfig()) {
            $findings[] = 'Ägarrollens lösenord finns i programmets konfiguration (DB_MIGRATION_PASSWORD eller DB_MIGRATION_URL i .env eller den cachade konfigurationen). Kod som körs i NONOX kan då logga in som ägaren. Ta bort det och låt ägaren logga in på annat sätt (docs/drift/databasroller.md).';
        }

        if ($superuser) {
            $findings[] = "Rollen {$role} är superanvändare. Den kan stänga av alla skydd i databasen, även session_replication_role.";
        }

        $owned = (int) $this->connection->scalar(<<<'SQL'
            select count(*) from pg_class
            where relnamespace = current_schema()::regnamespace and relkind in ('r', 'p')
              and pg_has_role(current_user, relowner, 'MEMBER')
            SQL);

        if ($owned > 0) {
            $findings[] = "Rollen {$role} äger {$owned} tabeller (eller är medlem i rollen som äger dem). Den kan stänga av, ta bort och ersätta triggerna som skyddar bokföringen.";
        }

        $functions = (int) $this->connection->scalar(<<<'SQL'
            select count(distinct p.oid) from pg_trigger t join pg_proc p on p.oid = t.tgfoid
            where not t.tgisinternal and pg_has_role(current_user, p.proowner, 'MEMBER')
            SQL);

        if ($functions > 0) {
            $findings[] = "Rollen {$role} äger {$functions} triggerfunktioner och kan ersätta dem med tomma.";
        }

        if (! $superuser) {
            $findings = [...$findings, ...$this->privilegeFindings($role)];
        }

        if (DatabaseRoles::enabled()) {
            $undecided = DatabaseGrants::undecided($this->connection);

            if ($undecided !== []) {
                $findings[] = 'Tabeller utan behörighetsbeslut (appen får ingen åtkomst till dem): '.implode(', ', $undecided).'.';
            }
        }

        return $findings;
    }

    private function ownerPasswordInConfig(): bool
    {
        if (filled($this->ownerConfig['password'] ?? null)) {
            return true;
        }

        $url = $this->ownerConfig['url'] ?? null;

        return is_string($url) && filled(parse_url($url, PHP_URL_PASS));
    }

    /** @return list<string> */
    private function privilegeFindings(string $role): array
    {
        $findings = [];

        $trigger = $this->tablesWith('TRIGGER');

        if ($trigger !== []) {
            $findings[] = "Rollen {$role} har behörigheten TRIGGER på: ".implode(', ', $trigger).'.';
        }

        $truncate = $this->tablesWith('TRUNCATE');

        if ($truncate !== []) {
            $findings[] = "Rollen {$role} kan tömma (TRUNCATE): ".implode(', ', $truncate).'.';
        }

        if ((bool) $this->connection->scalar("select has_schema_privilege(current_user, current_schema(), 'CREATE')")) {
            $findings[] = "Rollen {$role} kan skapa tabeller och funktioner i schemat.";
        }

        if ((bool) $this->connection->scalar("select has_database_privilege(current_user, current_database(), 'TEMP')")) {
            $findings[] = "Rollen {$role} kan skapa tillfälliga tabeller (TEMP på databasen).";
        }

        if ((bool) $this->connection->scalar("select has_function_privilege(current_user, 'lo_create(oid)', 'EXECUTE')")) {
            $findings[] = "Rollen {$role} kan skapa stora objekt (lo_create) i databasen.";
        }

        $memberships = array_values(array_filter(
            self::DANGEROUS_ROLES,
            fn (string $predefined): bool => (bool) $this->connection->scalar(
                "select exists (select 1 from pg_roles where rolname = ?) and pg_has_role(current_user, ?, 'MEMBER')",
                [$predefined, $predefined],
            ),
        ));

        if ($memberships !== []) {
            $findings[] = "Rollen {$role} är medlem i ".implode(', ', $memberships).' och når serverns filer eller alla tabeller.';
        }

        if (DatabaseRoles::enabled()) {
            $mismatched = $this->mismatchedTables();

            if ($mismatched !== []) {
                $findings[] = 'Behörigheterna följer inte besluten för: '.implode(', ', $mismatched).'. Kör `php artisan nonox:db-grants`.';
            }
        }

        return $findings;
    }

    /** @return list<string> */
    private function tablesWith(string $privilege): array
    {
        return array_values(array_map(fn (object $row): string => (string) data_get($row, 'relname'), $this->connection->select(<<<SQL
            select relname from pg_class
            where relnamespace = current_schema()::regnamespace and relkind in ('r', 'p')
              and has_table_privilege(current_user, oid, '{$privilege}')
            order by relname
            SQL)));
    }

    /**
     * Tables where the role's privileges differ from the decision, column privileges included.
     *
     * @return list<string>
     */
    private function mismatchedTables(): array
    {
        $rows = $this->connection->select(<<<'SQL'
            select c.relname,
                has_table_privilege(current_user, c.oid, 'SELECT') as s,
                has_table_privilege(current_user, c.oid, 'INSERT') as i,
                has_table_privilege(current_user, c.oid, 'UPDATE') as u,
                has_table_privilege(current_user, c.oid, 'DELETE') as d,
                coalesce((select string_agg(a.attname, ',' order by a.attname) from pg_attribute a
                    where a.attrelid = c.oid and a.attnum > 0 and not a.attisdropped
                      and has_column_privilege(current_user, c.oid, a.attnum, 'UPDATE')), '') as update_columns,
                coalesce((select string_agg(a.attname, ',' order by a.attname) from pg_attribute a
                    where a.attrelid = c.oid and a.attnum > 0 and not a.attisdropped
                      and has_column_privilege(current_user, c.oid, a.attnum, 'INSERT')), '') as insert_columns
            from pg_class c
            where c.relnamespace = current_schema()::regnamespace and c.relkind in ('r', 'p')
            order by c.relname
            SQL);

        $decisions = DatabaseGrants::TABLES;
        $mismatched = [];

        foreach ($rows as $row) {
            $table = (string) data_get($row, 'relname');

            if (! array_key_exists($table, $decisions)) {
                continue;
            }

            $decision = $decisions[$table];
            $privileges = $decision->privileges();

            $actual = array_keys(array_filter([
                'SELECT' => (bool) data_get($row, 's'),
                'INSERT' => (bool) data_get($row, 'i'),
                'UPDATE' => (bool) data_get($row, 'u'),
                'DELETE' => (bool) data_get($row, 'd'),
            ]));

            // Without the table privilege, only the decision's own column privileges may exist.
            $expectedUpdateColumns = $decision === GrantDecision::AppendOnlyLockable ? 'id' : '';
            $columnsDiffer = (! in_array('UPDATE', $privileges, true) && (string) data_get($row, 'update_columns') !== $expectedUpdateColumns)
                || (! in_array('INSERT', $privileges, true) && (string) data_get($row, 'insert_columns') !== '');

            if ($actual !== $privileges || $columnsDiffer) {
                $mismatched[] = $table;
            }
        }

        return $mismatched;
    }
}
