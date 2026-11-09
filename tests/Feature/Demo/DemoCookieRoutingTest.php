<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Demo\DemoMode;
use App\Support\Demo\DemoSessions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Bookkeeping;
use Tests\Support\Demo;

/*
 * A request goes to the demo database only when the feature is on, the cookie's
 * session exists in the demo database and its demo is live (ADR-077, addendum).
 * Every other request is served by the main database, and a demo cookie it
 * carries is forgotten. None of it may ever error or plant a cookie.
 */

beforeEach(function () {
    Demo::enable();

    Route::middleware('web')->get('/_probe', fn () => response()->json(['connection' => DB::getDefaultConnection(), 'user' => Auth::user()?->email]));
});

/** A guest with a live demo and a session in the demo database. */
function liveGuestSession(object $test): array
{
    [$guest] = Demo::company();
    $test->get(route('demo.open', Demo::token($guest)))->assertRedirect(route('dashboard'));
    (fn () => $this->defaultCookies = [])->call($test);

    return [$guest, Demo::sessionId()];
}

it('serves a live demo session on the demo database', function () {
    [$guest, $session] = liveGuestSession($this);

    $this->withCookie(DemoMode::COOKIE, $session)->get('/_probe')->assertJson(['connection' => 'demo', 'user' => $guest->email]);
});

it('plants no cookie for a bare opening link, one nobody asked for', function () {
    $response = $this->get(route('demo.open', Str::random(40)));

    $response->assertRedirect(route('demo.show'))->assertCookieMissing(DemoMode::COOKIE);
    expect(collect($response->headers->getCookies())->contains(fn ($cookie) => $cookie->getName() === DemoMode::COOKIE))->toBeFalse();
});

it('plants no cookie while the feature is off, not even for a ready token', function () {
    [$guest] = Demo::company();
    $token = Demo::token($guest);
    Demo::enable(false);

    $this->get(route('demo.open', $token))->assertNotFound()->assertCookieMissing(DemoMode::COOKIE);
});

it('serves the main database, and forgets the cookie, for a session that is not in the demo database', function () {
    $this->withCookie(DemoMode::COOKIE, Str::random(40))->get('/_probe')
        ->assertJson(['connection' => config('database.default'), 'user' => null])
        ->assertCookieExpired(DemoMode::COOKIE);
});

it('serves the main database, and forgets the cookie, once the demo has ended', function () {
    [$guest, $session] = liveGuestSession($this);
    DB::connection('demo')->table('demo_sessions')->where('user_id', $guest->id)->update(['expires_at' => CarbonImmutable::now()->subMinute()]);

    $this->withCookie(DemoMode::COOKIE, $session)->get('/_probe')
        ->assertJson(['connection' => config('database.default'), 'user' => null])
        ->assertCookieExpired(DemoMode::COOKIE);
});

it('serves the main database, and forgets the cookie, while the feature is off', function () {
    [, $session] = liveGuestSession($this);
    Demo::enable(false);

    $this->withCookie(DemoMode::COOKIE, $session)->get('/_probe')
        ->assertJson(['connection' => config('database.default'), 'user' => null])
        ->assertCookieExpired(DemoMode::COOKIE);
});

it('never errors when the demo database is missing, for a cookie or an opening link', function () {
    config(['database.connections.demo.database' => 'a_database_that_is_not_there_demo']);
    DB::purge('demo');

    $this->withCookie(DemoMode::COOKIE, Str::random(40))->get('/_probe')
        ->assertOk()
        ->assertJson(['connection' => config('database.default')])
        ->assertCookieExpired(DemoMode::COOKIE);

    $this->get(route('demo.open', Str::random(40)))->assertRedirect(route('demo.show'));
});

it('opens a demo only for a token whose build is ready', function () {
    [$guest] = Demo::company();
    $ready = Demo::token($guest);
    $building = Str::random(40);
    DemoSessions::store()->put('demo:build:'.hash('sha256', $building), ['status' => DemoSessions::BUILDING, 'industry' => 'x'], 600);

    $this->get(route('demo.open', $building))->assertRedirect(route('demo.show'))->assertCookieMissing(DemoMode::COOKIE);
    $this->get(route('demo.open', $ready))->assertRedirect(route('dashboard'))->assertCookie(DemoMode::COOKIE);
});

it('runs every way to sign up on the main database and forgets the demo cookie', function () {
    [, $session] = liveGuestSession($this);

    foreach (['/logga-in', '/registrera', '/ko'] as $path) {
        $this->withCookie(DemoMode::COOKIE, $session)->get($path)->assertCookieExpired(DemoMode::COOKIE);
        $this->defaultCookies = [];
    }

    config(['app.landing.enabled' => true]);
    $this->withCookie(DemoMode::COOKIE, $session)->get(route('waitlist'))->assertOk()->assertCookieExpired(DemoMode::COOKIE);
});

it('forgets the cookie when a guest signs out', function () {
    [, $session] = liveGuestSession($this);

    $this->withCookie(DemoMode::COOKIE, $session)->post(route('logout'))->assertRedirect('/')->assertCookieExpired(DemoMode::COOKIE);
});

it('does not forget the cookie on the entry pages, so a guest can look at them and go back', function () {
    [, $session] = liveGuestSession($this);

    $response = $this->withCookie(DemoMode::COOKIE, $session)->get(route('demo.show'));

    expect(collect($response->headers->getCookies())->contains(fn ($cookie) => $cookie->getName() === DemoMode::COOKIE))->toBeFalse();
    $this->defaultCookies = [];

    [$member] = Bookkeeping::company();
    $this->actingAs($member)->get('/_probe')->assertJson(['connection' => config('database.default')]);
    expect(User::query()->whereKey($member->id)->exists())->toBeTrue();
});
