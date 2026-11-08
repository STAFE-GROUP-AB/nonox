<?php

declare(strict_types=1);

use App\Actions\Demo\PurgeExpiredDemos;
use App\Support\Demo\DemoDatabase;
use App\Support\Demo\DemoMode;
use App\Support\Demo\DemoSessions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\Demo;

/*
 * demo:prune (ADR-077, addendum 2026-11-08) runs only against the demo
 * connection and refuses anything but a database that is not the main one and
 * whose name ends in _demo. The rebuild itself is tested in Feature/DemoRebuild.
 */

afterEach(function () {
    File::delete(storage_path('framework/demo-built-at'));
    DemoDatabase::stopDraining();
});

function builtLongAgo(): void
{
    File::put(storage_path('framework/demo-built-at'), (string) CarbonImmutable::now()->subHours(30)->getTimestamp());
}

it('schedules the demo database upkeep every ten minutes', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'demo:prune'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/10 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('refuses to run when the demo connection points at the main database', function () {
    $main = (string) config('database.connections.'.DemoMode::mainConnection().'.database');
    config(['database.connections.demo.database' => $main]);
    DB::purge('demo');

    $this->artisan('demo:prune')->expectsOutputToContain('is the main database')->assertFailed();

    expect(fn () => app(PurgeExpiredDemos::class)())->toThrow(RuntimeException::class);

    // Also a name that does not end in _demo, however it is spelled.
    config(['database.connections.pgsql.database' => 'main_database', 'database.connections.demo.database' => 'main_database']);
    expect(fn () => DemoDatabase::assertSafe())->toThrow(RuntimeException::class, 'does not end in _demo');
});

it('refuses to run when the demo database\'s name does not end in _demo', function () {
    config(['database.connections.demo.database' => 'postgres']);
    DB::purge('demo');

    $this->artisan('demo:prune')->expectsOutputToContain('does not end in _demo')->assertFailed();

    config(['database.connections.demo.database' => 'nonox_test_demo_x']);
    expect(fn () => DemoDatabase::assertSafe())->toThrow(RuntimeException::class, 'does not end in _demo');
});

it('only checks and does nothing when no demo has expired', function () {
    $this->artisan('demo:prune')->expectsOutputToContain('Inget att bygga om')->assertSuccessful();

    expect(DB::connection('demo')->getSchemaBuilder()->hasTable('users'))->toBeTrue();
});

it('does not touch the demos that are live, and starts to drain when the database is due for a rebuild', function () {
    Demo::company();
    Demo::company(CarbonImmutable::now()->subHour());
    $demoUsers = DB::connection('demo')->table('users')->count();

    $this->artisan('demo:prune')->assertSuccessful();
    expect(DemoDatabase::draining())->toBeFalse();

    builtLongAgo();
    $result = app(PurgeExpiredDemos::class)();

    expect($result['rebuilt'])->toBeFalse()
        ->and($result['live'])->toBe(1)
        ->and($result['expired'])->toBe(1)
        ->and($result['draining'])->toBeTrue()
        ->and(DB::connection('demo')->table('users')->count())->toBe($demoUsers);
});

it('does not rebuild while a build is running', function () {
    Demo::company(CarbonImmutable::now()->subHour());
    DemoSessions::store()->put('demo:building', 1, 600);

    expect(app(PurgeExpiredDemos::class)()['rebuilt'])->toBeFalse()
        ->and(DB::connection('demo')->table('users')->count())->toBeGreaterThan(0);
});
