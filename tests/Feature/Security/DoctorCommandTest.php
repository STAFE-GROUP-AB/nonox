<?php

declare(strict_types=1);

use App\Support\Database\DatabaseRoles;
use Illuminate\Support\Facades\DB;
use Tests\Support\DatabaseRolesHarness;

/*
 * ADR-079 build step 2: `php artisan nonox:doctor` warns when the runtime
 * role could switch off the protection of the books, and only reads.
 */

pest()->group('database-roles');

it('warns when NONOX runs as a superuser that owns the tables, the single-user mode', function () {
    if (! DatabaseRolesHarness::superuser()) {
        $this->markTestSkipped('The test database user is not a superuser.');
    }

    config(['database.roles.enabled' => false]);

    $this->artisan('nonox:doctor')
        ->expectsOutputToContain('Databasrollerna är inte påslagna')
        ->expectsOutputToContain('är superanvändare')
        ->expectsOutputToContain('triggerfunktioner')
        ->assertFailed();
});

it('reports no warnings as nonox_app with the grants applied', function () {
    DatabaseRolesHarness::becomeApp();
    config(['database.roles.enabled' => true, 'database.connections.pgsql_owner.password' => '', 'database.connections.pgsql_owner.url' => null]);

    $this->artisan('nonox:doctor')
        ->expectsOutputToContain('NONOX körs som databasrollen '.DatabaseRoles::APP)
        ->expectsOutputToContain('OK: rollen äger inga tabeller')
        ->doesntExpectOutputToContain('Varning')
        ->assertSuccessful();
});

it('warns about TRUNCATE, TRIGGER, schema CREATE and grants that differ from the decisions', function () {
    if (! DatabaseRolesHarness::superuser()) {
        $this->markTestSkipped('Granting too much inside the test needs a superuser.');
    }

    DatabaseRolesHarness::install();
    DB::statement('GRANT TRUNCATE, TRIGGER ON vouchers TO nonox_app');
    DB::statement('GRANT UPDATE ON audit_logs TO nonox_app');
    DB::statement('GRANT CREATE ON SCHEMA public TO nonox_app');
    DB::statement('SET LOCAL ROLE '.DatabaseRoles::APP);
    config(['database.roles.enabled' => true]);

    $this->artisan('nonox:doctor')
        ->expectsOutputToContain('har behörigheten TRIGGER på: vouchers')
        ->expectsOutputToContain('kan tömma (TRUNCATE): vouchers')
        ->expectsOutputToContain('kan skapa tabeller och funktioner i schemat')
        ->expectsOutputToContain('Behörigheterna följer inte besluten för: audit_logs')
        ->assertFailed();
});

it('warns about temporary tables, large objects, server-file roles and column grants that differ', function () {
    if (! DatabaseRolesHarness::superuser()) {
        $this->markTestSkipped('Granting too much inside the test needs a superuser.');
    }

    DatabaseRolesHarness::install();
    DB::statement('GRANT TEMPORARY ON DATABASE '.DB::scalar('select current_database()').' TO nonox_app');
    DB::statement('GRANT EXECUTE ON FUNCTION lo_create(oid) TO nonox_app');
    DB::statement('GRANT pg_read_server_files TO nonox_app');
    DB::statement('GRANT UPDATE (action) ON audit_logs TO nonox_app');
    DB::statement('SET LOCAL ROLE '.DatabaseRoles::APP);
    config(['database.roles.enabled' => true]);

    $this->artisan('nonox:doctor')
        ->expectsOutputToContain('kan skapa tillfälliga tabeller')
        ->expectsOutputToContain('kan skapa stora objekt')
        ->expectsOutputToContain('pg_read_server_files')
        ->expectsOutputToContain('Behörigheterna följer inte besluten för: audit_logs')
        ->assertFailed();
});

it('warns when the running configuration holds the owner password', function (array $owner) {
    config(['database.roles.enabled' => true]);
    config(['database.connections.pgsql_owner' => [...config('database.connections.pgsql_owner'), ...$owner]]);

    $this->artisan('nonox:doctor')
        ->expectsOutputToContain('Ägarrollens lösenord finns i programmets konfiguration')
        ->assertFailed();
})->with([
    'password' => [['password' => 'hemligt', 'url' => null]],
    'url' => [['password' => '', 'url' => 'pgsql://nonox_owner:hemligt@127.0.0.1/nonox']],
]);

it('warns when the runtime role is a member of the owner role', function () {
    if (! DatabaseRolesHarness::superuser()) {
        $this->markTestSkipped('Changing memberships inside the test needs a superuser.');
    }

    DatabaseRolesHarness::install();
    DB::statement('GRANT nonox_owner TO nonox_app');
    DB::statement('SET LOCAL ROLE '.DatabaseRoles::APP);
    config(['database.roles.enabled' => true]);

    $this->artisan('nonox:doctor')
        ->expectsOutputToContain('eller är medlem i rollen som äger dem')
        ->assertFailed();
});

it('only reads', function () {
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = trim($query->sql);
    });

    $this->artisan('nonox:doctor')->run();

    expect($statements)->not->toBeEmpty();

    foreach ($statements as $sql) {
        expect(strtolower($sql))->toStartWith('select');
    }
});
