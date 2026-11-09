<?php

declare(strict_types=1);

use App\Support\Database\DatabaseGrants;
use App\Support\Database\DatabaseRoles;
use App\Support\Database\GrantDecision;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\DatabaseRolesHarness;

/*
 * ADR-079 build step 2: every table has an explicit grant decision, the
 * decisions follow the triggers, the scripts under database/roles/ are what
 * the code generates, and a migrate re-applies the grants as the owner.
 */

uses(WithConsoleEvents::class);

pest()->group('database-roles');

/** @return list<string> */
function schemaTables(): array
{
    return array_map(fn (object $row): string => (string) $row->tablename, DB::select(
        'select tablename from pg_tables where schemaname = current_schema() order by tablename'
    ));
}

/** @return list<string> */
function privilegesOf(string $role, string $table): array
{
    return array_values(array_filter(
        ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'],
        fn (string $privilege): bool => (bool) DB::scalar('select has_table_privilege(?, ?, ?)', [$role, $table, $privilege]),
    ));
}

/**
 * config/database.php as it reads with these environment variables (null: unset), restored afterwards.
 *
 * @param  array<string, ?string>  $env
 * @return array<string, mixed>
 */
function databaseConfigWith(array $env): array
{
    $env += ['DB_MIGRATION_URL' => null, 'DB_MIGRATION_HOST' => null, 'DB_MIGRATION_PASSWORD' => null, 'DB_URL' => null];
    $set = function (string $key, mixed $value): void {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    };

    return (function () use ($env, $set): array {
        $saved = [];

        foreach ($env as $key => $value) {
            $saved[$key] = [getenv($key) === false ? null : getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            $set($key, $value);
        }

        try {
            return require base_path('config/database.php');
        } finally {
            foreach ($saved as $key => [$process, $env, $server]) {
                $process === null ? putenv($key) : putenv("{$key}={$process}");
                unset($_ENV[$key], $_SERVER[$key]);

                if ($env !== null) {
                    $_ENV[$key] = $env;
                }

                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }
            }
        }
    })();
}

function requireSuperuser(): void
{
    if (! DatabaseRolesHarness::superuser()) {
        test()->markTestSkipped('Creating the roles inside the test needs a superuser.');
    }
}

it('has a grant decision for every table in the schema, and none for a table that does not exist', function () {
    $tables = schemaTables();

    expect(array_values(array_diff($tables, array_keys(DatabaseGrants::TABLES))))->toBe([])
        ->and(array_values(array_diff(array_keys(DatabaseGrants::TABLES), $tables)))->toBe([]);
});

it('makes every table whose trigger refuses all changes and deletions append-only for the app', function () {
    // A trigger function without RETURN can only raise: the table's rows can never be changed or deleted.
    $refusing = collect(DB::select(<<<'SQL'
        select distinct c.relname from pg_trigger t
        join pg_class c on c.oid = t.tgrelid
        join pg_proc p on p.oid = t.tgfoid
        where not t.tgisinternal
          and (t.tgtype & 8) <> 0 and (t.tgtype & 16) <> 0
          and p.prosrc !~* 'return'
        order by c.relname
        SQL))->pluck('relname')->all();

    expect($refusing)->not->toBeEmpty();

    foreach ($refusing as $table) {
        expect(DatabaseGrants::TABLES[$table] ?? null)->toBeIn([GrantDecision::AppendOnly, GrantDecision::AppendOnlyLockable], "{$table} refuses every UPDATE and DELETE in its trigger");
    }
});

it('never gives the app UPDATE or DELETE on the audit log or the staff ledger', function () {
    expect(DatabaseGrants::TABLES['audit_logs'])->toBe(GrantDecision::AppendOnly)
        ->and(DatabaseGrants::TABLES['system_documentation_versions'])->toBe(GrantDecision::AppendOnly)
        ->and(DatabaseGrants::TABLES['staff_ledger_entries']->privileges())->not->toContain('UPDATE')
        ->and(DatabaseGrants::TABLES['migrations'])->toBe(GrantDecision::ReadOnly);
});

it('ships grants.sql exactly as the code generates it', function () {
    expect((string) file_get_contents(base_path(DatabaseGrants::FILE)))
        ->toBe(DatabaseGrants::sql(), 'Run `php artisan nonox:db-grants --write`.');
});

it('changes no data in the role scripts', function () {
    foreach (['roles.sql', 'transfer-ownership.sql', 'grants.sql'] as $file) {
        $sql = (string) preg_replace('/--.*$/m', '', (string) file_get_contents(base_path("database/roles/{$file}")));

        expect($sql)->not->toMatch('/\b(INSERT\s+INTO|DELETE\s+FROM|UPDATE\s+\w+\s+SET|TRUNCATE\s+(TABLE\s+)?\w|DROP\s+(TABLE|DATABASE|SCHEMA|TRIGGER|FUNCTION))\b/i');
    }
});

it('creates the roles idempotently, none of them able to do more than its job', function () {
    requireSuperuser();

    DatabaseRolesHarness::install();
    DatabaseRolesHarness::install();

    foreach ([DatabaseRoles::OWNER, DatabaseRoles::APP, DatabaseRoles::READONLY] as $role) {
        $attributes = DB::selectOne('select rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls from pg_roles where rolname = ?', [$role]);

        expect((array) $attributes)->toBe(['rolsuper' => false, 'rolcreatedb' => false, 'rolcreaterole' => false, 'rolreplication' => false, 'rolbypassrls' => false]);
    }

    expect(DB::scalar("select count(*) from pg_class where relnamespace = current_schema()::regnamespace and relkind in ('r', 'p', 'S', 'v', 'm') and relowner <> 'nonox_owner'::regrole"))->toBe(0)
        ->and(DB::scalar("select count(*) from pg_proc where pronamespace = current_schema()::regnamespace and proowner <> 'nonox_owner'::regrole and not exists (select 1 from pg_depend d where d.classid = 'pg_proc'::regclass and d.objid = pg_proc.oid and d.deptype = 'e')"))->toBe(0)
        ->and((bool) DB::scalar("select has_schema_privilege('nonox_app', current_schema(), 'CREATE')"))->toBeFalse()
        ->and((bool) DB::scalar("select has_schema_privilege('nonox_readonly', current_schema(), 'CREATE')"))->toBeFalse()
        ->and((bool) DB::scalar("select pg_has_role('nonox_app', 'nonox_owner', 'USAGE')"))->toBeFalse();
});

it('gives the app exactly the privileges of each table decision, and the reader SELECT only', function () {
    requireSuperuser();

    DatabaseRolesHarness::install();

    foreach (DatabaseGrants::TABLES as $table => $decision) {
        expect(privilegesOf(DatabaseRoles::APP, $table))->toBe($decision->privileges(), $table)
            ->and(privilegesOf(DatabaseRoles::READONLY, $table))->toBe(['SELECT'], $table);
    }

    // A lockable append-only table: UPDATE on the id column only, for SELECT … FOR UPDATE.
    expect((bool) DB::scalar("select has_column_privilege('nonox_app', 'vat_reports', 'id', 'UPDATE')"))->toBeTrue()
        ->and((bool) DB::scalar("select has_column_privilege('nonox_app', 'vat_reports', 'company_id', 'UPDATE')"))->toBeFalse()
        ->and((bool) DB::scalar("select has_column_privilege('nonox_app', 'audit_logs', 'id', 'UPDATE')"))->toBeFalse();

    $sequences = DB::select("select oid::regclass::text as name from pg_class where relnamespace = current_schema()::regnamespace and relkind = 'S'");

    foreach ($sequences as $sequence) {
        expect((bool) DB::scalar("select has_sequence_privilege('nonox_app', ?, 'USAGE')", [$sequence->name]))->toBeTrue($sequence->name)
            ->and((bool) DB::scalar("select has_sequence_privilege('nonox_app', ?, 'UPDATE')", [$sequence->name]))->toBeFalse($sequence->name);
    }
});

it('gives a table without a decision nothing for the app, so a forgotten table fails closed', function () {
    requireSuperuser();

    DatabaseRolesHarness::install();
    DB::statement('CREATE TABLE roles_probe_undecided (id bigint)');
    DB::statement('GRANT ALL ON roles_probe_undecided TO nonox_app');

    DatabaseGrants::apply(DB::connection());

    expect(privilegesOf(DatabaseRoles::APP, 'roles_probe_undecided'))->toBe([])
        ->and(privilegesOf(DatabaseRoles::READONLY, 'roles_probe_undecided'))->toBe(['SELECT'])
        ->and(DatabaseGrants::undecided(DB::connection()))->toBe(['roles_probe_undecided']);
});

it('re-applies the grants as the owner after every migrate when the roles are on', function (object $event) {
    requireSuperuser();

    DatabaseRolesHarness::install();
    DB::statement('GRANT UPDATE, DELETE, TRUNCATE ON audit_logs TO nonox_app');
    config(['database.roles.enabled' => true, 'database.roles.migration_connection' => 'pgsql']);

    event($event);

    expect(privilegesOf(DatabaseRoles::APP, 'audit_logs'))->toBe(['SELECT', 'INSERT']);
})->with([
    'migrations ran' => fn () => new MigrationsEnded('up'),
    'nothing pending' => fn () => new NoPendingMigrations('up'),
]);

it('re-pins every trigger function after a migrate, with or without the roles', function () {
    if (! DatabaseRoles::canAlterLedger(DB::connection())) {
        $this->markTestSkipped('Needs the owner of the trigger functions (the single user, or a superuser in the test).');
    }

    config(['database.roles.enabled' => false]);
    DB::statement('ALTER FUNCTION vouchers_protect_posted() RESET search_path');
    expect(DB::scalar("select proconfig from pg_proc where proname = 'vouchers_protect_posted'"))->toBeNull();

    event(new NoPendingMigrations('up'));

    expect(str_replace(['{', '}', '"'], '', (string) DB::scalar("select proconfig from pg_proc where proname = 'vouchers_protect_posted'")))->toBe('search_path=public, pg_temp');
});

it('applies the grants only after a migrate of the main database, not of another connection', function () {
    requireSuperuser();

    DatabaseRolesHarness::install();
    DB::statement('GRANT UPDATE ON audit_logs TO nonox_app');
    // The owner connection is another one than the connection that just migrated (a demo database, say).
    config(['database.roles.enabled' => true, 'database.roles.migration_connection' => 'pgsql_owner']);

    event(new NoPendingMigrations('up'));

    expect(privilegesOf(DatabaseRoles::APP, 'audit_logs'))->toBe(['SELECT', 'INSERT', 'UPDATE']);
});

it('applies the grants after a migrate that failed, as the owner', function () {
    requireSuperuser();

    DatabaseRolesHarness::install();
    DB::statement('GRANT UPDATE, DELETE ON audit_logs TO nonox_app');
    config(['database.roles.enabled' => true, 'database.roles.migration_connection' => 'pgsql']);
    $input = new ArrayInput(['--database' => 'pgsql'], new InputDefinition([new InputOption('database', null, InputOption::VALUE_OPTIONAL)]));

    event(new CommandFinished('migrate', $input, new NullOutput, 1));

    expect(privilegesOf(DatabaseRoles::APP, 'audit_logs'))->toBe(['SELECT', 'INSERT']);
});

it('lets a registered approver allow schema commands on its own connection only', function () {
    config(['database.roles.enabled' => true, 'database.roles.migration_connection' => 'pgsql_owner']);
    DatabaseRoles::approveConnectionsUsing('test-sandbox', fn (string $connection): bool => $connection === 'sandbox');

    try {
        expect(DatabaseRoles::refusal('migrate', 'sandbox'))->toBeNull()
            ->and(DatabaseRoles::refusal('migrate', 'pgsql'))->not->toBeNull()
            ->and(DatabaseRoles::refusal('migrate', null))->not->toBeNull();
    } finally {
        DatabaseRoles::approveConnectionsUsing('test-sandbox', fn (string $connection): bool => false);
    }
});

it('lets the demo connection pass the guard only when the demo database is safe', function () {
    config(['database.roles.enabled' => true, 'database.roles.migration_connection' => 'pgsql_owner']);

    // A demo connection pointing at the main database is refused by DemoDatabase::assertSafe(), with its reason.
    config(['database.connections.demo.database' => config('database.connections.pgsql.database')]);
    expect(fn () => DatabaseRoles::refusal('migrate', 'demo'))->toThrow(RuntimeException::class);

    // Another connection is never approved by the demo.
    expect(DatabaseRoles::refusal('migrate', 'reports'))->not->toBeNull();
});

it('takes temporary tables and large objects away from the app and the reader', function () {
    requireSuperuser();

    DatabaseRolesHarness::install();

    foreach ([DatabaseRoles::APP, DatabaseRoles::READONLY] as $role) {
        expect((bool) DB::scalar('select has_database_privilege(?, current_database(), ?)', [$role, 'TEMP']))->toBeFalse($role)
            ->and((bool) DB::scalar("select has_function_privilege(?, 'lo_create(oid)', 'EXECUTE')", [$role]))->toBeFalse($role);
    }
});

it('never takes the owner password from DB_PASSWORD with the roles on, and honours DB_MIGRATION_URL', function () {
    $roles = databaseConfigWith(['DB_MIGRATION_USERNAME' => 'nonox_owner', 'DB_MIGRATION_PASSWORD' => null, 'DB_USERNAME' => 'nonox_app', 'DB_PASSWORD' => 'app-secret', 'DB_MIGRATION_HOST' => '/var/run/postgresql']);
    $url = databaseConfigWith(['DB_MIGRATION_USERNAME' => 'nonox_owner', 'DB_MIGRATION_URL' => 'pgsql://nonox_owner@db.internal:5433/nonox', 'DB_URL' => 'pgsql://nonox_app:x@db.internal:5433/nonox']);
    $single = databaseConfigWith(['DB_MIGRATION_USERNAME' => null, 'DB_URL' => 'pgsql://nonox:x@db.internal:5433/nonox']);

    expect($roles['connections']['pgsql_owner']['password'])->toBe('')
        ->and($roles['connections']['pgsql_owner']['host'])->toBe('/var/run/postgresql')
        ->and($url['connections']['pgsql_owner']['url'])->toBe('pgsql://nonox_owner@db.internal:5433/nonox')
        ->and($single['connections']['pgsql_owner']['url'])->toBe('pgsql://nonox:x@db.internal:5433/nonox');
});

it('leaves the grants alone after a migrate when the roles are off', function () {
    config(['database.roles.enabled' => false]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    event(new NoPendingMigrations('up'));

    expect(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'GRANT'))->all())->toBe([]);
});

it('refuses schema commands that do not run as the owner when the roles are on', function () {
    config(['database.roles.enabled' => true, 'database.roles.migration_connection' => 'pgsql_owner']);

    expect(DatabaseRoles::refusal('migrate', null))->toContain('php artisan migrate --database=pgsql_owner')
        ->and(DatabaseRoles::refusal('migrate', 'pgsql'))->not->toBeNull()
        ->and(DatabaseRoles::refusal('migrate:rollback', null))->not->toBeNull()
        ->and(DatabaseRoles::refusal('migrate:fresh', null))->not->toBeNull()
        ->and(DatabaseRoles::refusal('db:wipe', null))->not->toBeNull()
        ->and(DatabaseRoles::refusal('migrate', 'pgsql_owner'))->toBeNull()
        ->and(DatabaseRoles::refusal('migrate:status', null))->toBeNull()
        ->and(DatabaseRoles::refusal('ledger:verify', null))->toBeNull()
        ->and(DatabaseRoles::migrateOptions())->toBe(['--database' => 'pgsql_owner'])
        ->and(DatabaseRoles::migrateCommand())->toBe('php artisan migrate --database=pgsql_owner');

    // The real command is stopped before it does anything (--pretend: even a failed guard would change nothing).
    expect(fn () => $this->artisan('migrate', ['--pretend' => true])->run())
        ->toThrow(RuntimeException::class, 'migrate --database=pgsql_owner');
});

it('changes nothing about migrating when the roles are off', function () {
    config(['database.roles.enabled' => false]);

    expect(DatabaseRoles::refusal('migrate', null))->toBeNull()
        ->and(DatabaseRoles::migrateOptions())->toBe([])
        ->and(DatabaseRoles::migrateCommand())->toBe('php artisan migrate');
});

it('turns the roles on only with DB_MIGRATION_USERNAME, and keeps one user otherwise', function () {
    $single = databaseConfigWith(['DB_MIGRATION_USERNAME' => null, 'DB_USERNAME' => 'nonox', 'DB_PASSWORD' => 'secret']);
    $roles = databaseConfigWith(['DB_MIGRATION_USERNAME' => 'nonox_owner', 'DB_MIGRATION_PASSWORD' => 'owner-secret', 'DB_USERNAME' => 'nonox_app', 'DB_PASSWORD' => 'app-secret']);

    expect($single['roles']['enabled'])->toBeFalse()
        ->and($single['connections']['pgsql_owner']['username'])->toBe('nonox')
        ->and($single['connections']['pgsql_owner']['password'])->toBe('secret')
        ->and($roles['roles']['enabled'])->toBeTrue()
        ->and($roles['connections']['pgsql']['username'])->toBe('nonox_app')
        ->and($roles['connections']['pgsql_owner']['username'])->toBe('nonox_owner')
        ->and($roles['connections']['pgsql_owner']['password'])->toBe('owner-secret')
        ->and($roles['connections']['pgsql_owner']['timezone'])->toBe('Europe/Stockholm');
});
