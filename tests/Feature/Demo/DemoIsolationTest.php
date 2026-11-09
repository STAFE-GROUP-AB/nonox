<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Support\Demo\DemoMode;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Bookkeeping;
use Tests\Support\Demo;

/*
 * The demo database is a database of its own (ADR-077, addendum 2026-11-08):
 * nothing about a demo is in the main one, a guest cookie never finds a main
 * user, a main session never finds the demo database, and the immutability
 * triggers of the main database have no exception.
 */

beforeEach(function () {
    Demo::enable();

    Route::middleware('web')->get('/_probe', fn () => response()->json([
        'connection' => DB::getDefaultConnection(),
        'session_driver' => config('session.driver'),
        'session_cookie' => config('session.cookie'),
        'cache' => config('cache.default'),
        'queue' => config('queue.default'),
        'user' => Auth::user()?->email,
    ]));
});

/** Opens a demo for the guest through the real route and returns the guest session's id. */
function openDemoAs(object $test, User $guest): string
{
    $token = Demo::token($guest);
    $test->get(route('demo.open', $token))->assertRedirect(route('dashboard'));

    return Demo::sessionId();
}

it('has no exception for demos in the main database\'s triggers, and the same functions as the demo database', function () {
    $definitions = fn (string $connection): array => collect(DB::connection($connection)->select(
        "select p.proname, pg_get_functiondef(p.oid) as definition from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'public' and p.prokind = 'f' order by p.proname",
    ))->pluck('definition', 'proname')->all();

    $main = $definitions(DemoMode::mainConnection());
    $demo = $definitions(DemoMode::CONNECTION);

    expect($main)->not->toBe([]);

    foreach ($main as $name => $definition) {
        expect($definition)->not->toContain('demo_purge', "{$name} has a demo exception");
    }

    expect($demo)->toBe($main);

    $tables = fn (string $connection): array => DB::connection($connection)->getSchemaBuilder()->getTableListing();

    expect($tables(DemoMode::mainConnection()))->not->toContain('public.demo_sessions')
        ->and($tables(DemoMode::CONNECTION))->toContain('public.demo_sessions');
});

it('switches a guest\'s whole request to the demo database, and puts the settings back afterwards', function () {
    [$guest] = Demo::company();
    $sessionId = openDemoAs($this, $guest);
    $main = config('database.default');

    $probe = $this->withCookie(DemoMode::COOKIE, $sessionId)->get('/_probe')->assertOk()->json();

    expect($probe)->toMatchArray([
        'connection' => 'demo',
        'session_driver' => 'demo-database',
        'session_cookie' => DemoMode::COOKIE,
        'cache' => 'demo',
        'queue' => 'demo',
        'user' => $guest->email,
    ]);

    expect(config('database.default'))->toBe($main)
        ->and(config('session.cookie'))->not->toBe(DemoMode::COOKIE)
        ->and(config('cache.default'))->not->toBe('demo')
        ->and(config('queue.default'))->not->toBe('demo');

    $this->defaultCookies = [];
    $plain = $this->get('/_probe')->assertOk()->json();

    expect($plain['connection'])->toBe($main)->and($plain['user'])->toBeNull();
});

it('runs the pages of the demo entry on the main database, also for a browser that has a demo', function () {
    [$guest] = Demo::company();
    $sessionId = openDemoAs($this, $guest);

    Route::middleware('web')->get('/prova/_probe', fn () => response()->json(['connection' => DB::getDefaultConnection(), 'user' => Auth::user()?->email]));

    $this->withCookie(DemoMode::COOKIE, $sessionId)->get('/prova/_probe')
        ->assertJson(['connection' => config('database.default'), 'user' => null]);
});

it('never resolves a main-database user from a guest cookie', function () {
    // A real session in the main database, signed in as a real user.
    [$real] = Bookkeeping::company();
    config(['session.driver' => 'database']);
    app('session')->forgetDrivers();
    $store = app('session')->driver();
    $store->start();
    Auth::guard('web')->login($real);
    $store->save();
    $mainSessionId = $store->getId();

    expect(DB::connection(DemoMode::mainConnection())->table('sessions')->where('id', $mainSessionId)->exists())->toBeTrue();

    Auth::guard('web')->logout();
    config(['session.driver' => 'array']);
    app('session')->forgetDrivers();

    // The main session's id sent under the guest cookie's name is looked up in the demo database's sessions only,
    // finds nothing there, and the request is served by the main database without a user.
    $probe = $this->withCookie(DemoMode::COOKIE, $mainSessionId)->get('/_probe')->assertOk()->assertCookieExpired(DemoMode::COOKIE)->json();

    expect($probe['connection'])->toBe(config('database.default'))->and($probe['user'])->toBeNull();

    $this->withCookie(DemoMode::COOKIE, $mainSessionId)->get(route('dashboard'))->assertRedirect(route('login'));
    $this->defaultCookies = [];
});

it('never lets a main-database session see the demo database', function () {
    [$guest, $company] = Demo::company();
    $guestSession = openDemoAs($this, $guest);

    // The guest's session id sent under the main session cookie's name, without the guest cookie.
    $probe = $this->withCookie((string) config('session.cookie'), $guestSession)->get('/_probe')->assertOk()->json();

    expect($probe['connection'])->toBe(config('database.default'))->and($probe['user'])->toBeNull();

    $this->withCookie((string) config('session.cookie'), $guestSession)->get(route('dashboard'))->assertRedirect(route('login'));
    $this->defaultCookies = [];

    // A real user in a real session is on the main database and does not see the demo company.
    [$real, $realCompany] = Bookkeeping::company();
    $this->actingAs($real);
    $seen = $this->get('/_probe')->json();

    expect($seen['connection'])->toBe(config('database.default'))
        ->and(Company::query()->where('org_number', $company->org_number)->exists())->toBeFalse()
        ->and(DB::connection(DemoMode::CONNECTION)->table('teams')->where('org_number', $realCompany->org_number)->exists())->toBeFalse();
});

it('keeps every row of a demo out of the main database', function () {
    [$guest, $company] = Demo::company();
    openDemoAs($this, $guest);

    $mainConnection = DB::connection(DemoMode::mainConnection());

    expect($mainConnection->table('users')->where('email', $guest->email)->exists())->toBeFalse()
        ->and($mainConnection->table('users')->where('email', 'like', '%@'.DemoMode::GUEST_DOMAIN)->exists())->toBeFalse()
        ->and($mainConnection->table('teams')->where('org_number', $company->org_number)->exists())->toBeFalse()
        ->and($mainConnection->table('audit_logs')->where('company_id', $company->id)->whereIn('company_id', $mainConnection->table('teams')->pluck('id'))->exists())->toBeFalse()
        ->and(DB::connection(DemoMode::CONNECTION)->table('teams')->where('org_number', $company->org_number)->exists())->toBeTrue()
        ->and(DB::connection(DemoMode::CONNECTION)->table('sessions')->count())->toBeGreaterThan(0);
});

it('carries the demo connection to a job the guest dispatches and to its worker', function () {
    [$guest] = Demo::company();
    $sessionId = openDemoAs($this, $guest);

    Route::middleware('web')->get('/_dispatch', function () {
        dispatch(fn () => null);

        return response()->json(['connection' => config('queue.default')]);
    });

    $this->withCookie(DemoMode::COOKIE, $sessionId)->get('/_dispatch')->assertJson(['connection' => 'demo']);

    // A worker's job from the demo queue runs on the demo database, and the worker goes back after it.
    $seen = [];

    $queued = fn () => Mockery::mock(Job::class)->shouldReceive('payload')->andReturn([])->getMock();
    $demoJob = $queued();
    $mainJob = $queued();
    $before = DB::getDefaultConnection();
    event(new JobProcessing('demo', $demoJob));
    $seen[] = DB::getDefaultConnection();
    event(new JobProcessed('demo', $demoJob));
    $seen[] = DB::getDefaultConnection();
    event(new JobProcessing('sync', $mainJob));
    $seen[] = DB::getDefaultConnection();
    event(new JobProcessed('sync', $mainJob));
    expect($seen)->toBe(['demo', $before, $before]);
    $this->defaultCookies = [];
});

it('treats a company in the demo database as a demo for every guard, and a main company as none', function () {
    [, $demoCompany] = Demo::company();
    [, $mainCompany] = Bookkeeping::company();

    expect(DemoMode::onDemoDatabase())->toBeFalse()
        ->and(DemoMode::active())->toBeFalse();

    DemoMode::onDemoConnection(function () use ($demoCompany) {
        expect(DemoMode::active())->toBeTrue()
            ->and(Company::query()->whereKey($demoCompany->id)->exists())->toBeTrue();
    });

    expect($mainCompany->id)->not->toBeNull();
});
