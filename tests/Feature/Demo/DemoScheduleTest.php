<?php

declare(strict_types=1);

use App\Actions\Demo\PurgeExpiredDemos;
use App\Support\Demo\DemoDatabase;
use App\Support\Demo\DemoMode;
use App\Support\Demo\DemoSessions;
use App\Support\Guide\Guide;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\Support\Demo;

/*
 * demo:prune (ADR-077, addendum 2026-11-08) runs only against the demo
 * connection and refuses anything but a database that is not the main one and
 * whose name ends in _demo. The rebuild itself is tested in Feature/DemoRebuild.
 */

beforeEach(fn () => Demo::enable());

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
    config(['database.connections.pgsql.database' => 'main_database', 'database.connections.demo.database' => 'other_name']);
    expect(fn () => DemoDatabase::assertSafe())->toThrow(RuntimeException::class, 'must be named exactly');
});

it('refuses to run when the demo database\'s name does not end in _demo', function () {
    config(['database.connections.demo.database' => 'postgres']);
    DB::purge('demo');

    $this->artisan('demo:prune')->expectsOutputToContain('must be named exactly')->assertFailed();

    config(['database.connections.demo.database' => 'nonox_test_demo_x']);
    expect(fn () => DemoDatabase::assertSafe())->toThrow(RuntimeException::class, 'must be named exactly');
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

it('refuses a foreign database that merely ends in _demo on the same server', function () {
    config(['database.connections.demo.database' => 'someone_elses_site_demo']);
    DB::purge('demo');

    expect(fn () => DemoDatabase::assertSafe())->toThrow(RuntimeException::class, 'must be named exactly');
    $this->artisan('demo:prune')->expectsOutputToContain('must be named exactly')->assertFailed();
});

it('refuses a demo connection on another host or port than the main one', function () {
    config(['database.connections.demo.host' => '127.0.0.2']);
    DB::purge('demo');
    expect(fn () => DemoDatabase::assertSafe())->toThrow(RuntimeException::class, "host is not the main connection's");

    config(['database.connections.demo.host' => config('database.connections.pgsql.host'), 'database.connections.demo.port' => '6543']);
    DB::purge('demo');
    expect(fn () => DemoDatabase::assertSafe())->toThrow(RuntimeException::class, "port is not the main connection's");
});

it('accepts exactly the main database\'s name with _demo, on the same host and port', function () {
    expect(fn () => DemoDatabase::assertSafe())->not->toThrow(RuntimeException::class)
        ->and(config('database.connections.demo.database'))->toBe(config('database.connections.pgsql.database').'_demo');
});

it('does nothing at all while the owner has not switched the demo on', function () {
    Demo::enable(false);
    Demo::company(CarbonImmutable::now()->subHour());
    $users = DB::connection('demo')->table('users')->count();

    // Not even a look at the database: a connection that would be refused is not asked.
    config(['database.connections.demo.database' => 'postgres']);

    $result = app(PurgeExpiredDemos::class)();

    expect($result['enabled'])->toBeFalse()->and($result['rebuilt'])->toBeFalse();
    $this->artisan('demo:prune')->expectsOutputToContain('avstängd')->assertSuccessful();

    expect(DB::connection('demo')->table('users')->count())->toBe($users);
});

it('lets a start win over a rebuild: the rebuild waits while a start holds the lock', function () {
    config(['database.connections.demo_lock' => config('database.connections.demo')]);
    $other = DB::connection('demo_lock');
    $other->selectOne('select pg_advisory_lock_shared(?)', [DemoDatabase::LOCK_KEY]);

    try {
        expect(DemoDatabase::rebuild())->toBeFalse()
            ->and(DB::connection('demo')->getSchemaBuilder()->hasTable('users'))->toBeTrue();
    } finally {
        $other->selectOne('select pg_advisory_unlock_shared(?)', [DemoDatabase::LOCK_KEY]);
    }
});

it('refuses a start at once, without waiting, while a rebuild holds the lock', function () {
    config(['database.connections.demo_lock' => config('database.connections.demo')]);
    $other = DB::connection('demo_lock');
    $other->selectOne('select pg_advisory_lock(?)', [DemoDatabase::LOCK_KEY]);

    try {
        $started = microtime(true);

        expect(fn () => DemoSessions::begin('bokforing-for-cafeer', 'a-session'))
            ->toThrow(ValidationException::class, 'Demon städas just nu')
            ->and(microtime(true) - $started)->toBeLessThan(2.0);
    } finally {
        $other->selectOne('select pg_advisory_unlock(?)', [DemoDatabase::LOCK_KEY]);
    }
});

it('looks again once the rebuild holds the lock, and does nothing if a demo has come to life', function () {
    Demo::company(CarbonImmutable::now()->subHour());

    expect(DemoDatabase::rebuild(fn (): bool => false))->toBeFalse()
        ->and(DB::connection('demo')->table('users')->count())->toBeGreaterThan(0)
        ->and(DB::connection('demo')->getSchemaBuilder()->hasTable('users'))->toBeTrue();
});

it('keeps raw IP addresses out of the cache keys of the calendar links', function () {
    config(['app.landing.enabled' => true]);
    $ip = '203.0.113.88';
    $token = str_repeat('a', 48);

    $this->withServerVariables(['REMOTE_ADDR' => $ip])->get('/foretagsguiden/'.Guide::CALENDAR_SLUG.'/'.$token.'.ics')->assertNotFound();
    $this->withServerVariables(['REMOTE_ADDR' => $ip])->get(route('crm.calendar.feed', $token))->assertNotFound();

    $hashed = hash_hmac('sha256', $ip, (string) config('app.key'));

    expect(RateLimiter::attempts('guide-calendar-miss:'.$hashed))->toBe(1)
        ->and(RateLimiter::attempts('guide-calendar-miss:'.$ip))->toBe(0)
        ->and(RateLimiter::attempts('crm-calendar-miss:'.$hashed))->toBe(1)
        ->and(RateLimiter::attempts('crm-calendar-miss:'.$ip))->toBe(0);
});
