<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\ConnectionInterface;

/**
 * Read-only checks of the runtime database role (ADR-079 §6): every query is a
 * SELECT against the catalogue. Each finding is a Swedish sentence for the
 * operator; no findings means the triggers hold against the running code.
 */
final class DatabaseDoctor
{
    public function __construct(private readonly ConnectionInterface $connection) {}

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

        if ($superuser) {
            $findings[] = "Rollen {$role} är superanvändare. Den kan stänga av alla skydd i databasen, även session_replication_role.";
        }

        $owned = (int) $this->connection->scalar(<<<'SQL'
            select count(*) from pg_class
            where relnamespace = current_schema()::regnamespace and relkind in ('r', 'p')
              and pg_has_role(current_user, relowner, 'USAGE')
            SQL);

        if ($owned > 0) {
            $findings[] = "Rollen {$role} äger {$owned} tabeller (eller är medlem i rollen som äger dem). Den kan stänga av, ta bort och ersätta triggerna som skyddar bokföringen.";
        }

        $functions = (int) $this->connection->scalar(<<<'SQL'
            select count(distinct p.oid) from pg_trigger t join pg_proc p on p.oid = t.tgfoid
            where not t.tgisinternal and pg_has_role(current_user, p.proowner, 'USAGE')
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
     * Tables where the role's privileges differ from the decision.
     *
     * @return list<string>
     */
    private function mismatchedTables(): array
    {
        $rows = $this->connection->select(<<<'SQL'
            select relname,
                has_table_privilege(current_user, oid, 'SELECT') as s,
                has_table_privilege(current_user, oid, 'INSERT') as i,
                has_table_privilege(current_user, oid, 'UPDATE') as u,
                has_table_privilege(current_user, oid, 'DELETE') as d
            from pg_class
            where relnamespace = current_schema()::regnamespace and relkind in ('r', 'p')
            order by relname
            SQL);

        $mismatched = [];

        $decisions = DatabaseGrants::TABLES;

        foreach ($rows as $row) {
            $table = (string) data_get($row, 'relname');

            if (! array_key_exists($table, $decisions)) {
                continue;
            }

            $actual = array_keys(array_filter([
                'SELECT' => (bool) data_get($row, 's'),
                'INSERT' => (bool) data_get($row, 'i'),
                'UPDATE' => (bool) data_get($row, 'u'),
                'DELETE' => (bool) data_get($row, 'd'),
            ]));

            if ($actual !== $decisions[$table]->privileges()) {
                $mismatched[] = $table;
            }
        }

        return $mismatched;
    }
}
