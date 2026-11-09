<?php

declare(strict_types=1);

use App\Support\Demo\DemoDatabase;
use App\Support\Demo\DemoMode;
use App\Support\Demo\DemoSessions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/*
 * Every test runs against the dedicated PostgreSQL test database inside a
 * transaction that is rolled back afterwards. Never rebuild the schema per test.
 */
pest()->extend(TestCase::class)
    ->use(DatabaseTransactions::class)
    ->in('Feature', 'Unit', 'Tenancy');

/*
 * Tests of the demo database (ADR-077) work in a transaction on the demo
 * connection too, rolled back afterwards, like every test does on the main one.
 * The rebuild tests are in Feature/DemoRebuild and do not: they drop the demo
 * database's schema.
 */
pest()->beforeEach(function () {
    DB::connection('demo')->beginTransaction();
})->afterEach(function () {
    // A test that leaves the demo connection as the default would have its main transaction rolled back on the wrong connection.
    $left = DB::getDefaultConnection();
    DB::setDefaultConnection(DemoMode::mainConnection());
    expect($left)->toBe(DemoMode::mainConnection(), 'the test left the demo connection as the default');
    DemoMode::deactivateRequest();

    while (DB::connection('demo')->transactionLevel() > 0) {
        DB::connection('demo')->rollBack();
    }

    DemoDatabase::stopDraining();
    DemoSessions::stopBeating();
})->in('Feature/Demo');
