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
    config(['database.roles.enabled' => true]);

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
