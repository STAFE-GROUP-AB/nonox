<?php

declare(strict_types=1);

use App\Actions\Hq\UpdateInstanceSettings;
use App\Jobs\BuildDemoCompany;
use App\Models\InstanceSetting;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoDatabase;
use App\Support\Demo\DemoMode;
use App\Support\Demo\DemoSessions;
use App\Support\Instance\InstanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\Bookkeeping;
use Tests\Support\Demo;

/*
 * The visitor's way in (ADR-077): /prova, the checks that keep it from being
 * abused without a third-party CAPTCHA, the wait, and the switch.
 */

function demoSetting(?bool $value): void
{
    if ($value === null) {
        InstanceSetting::query()->where('key', InstanceSettings::DEMO_ENABLED)->delete();
    } else {
        InstanceSetting::query()->updateOrCreate(['key' => InstanceSettings::DEMO_ENABLED], ['value' => $value]);
    }

    InstanceSettings::current()->forget();
}

/** @param  array<string, mixed>  $overrides */
function startDemo(object $test, array $overrides = []): TestResponse
{
    return $test->post(route('demo.start'), [
        'industry' => 'bokforing-for-cafeer',
        'website' => '',
        'started' => Crypt::encryptString((string) (time() - 30)),
        ...$overrides,
    ]);
}

function tokenOf(TestResponse $response): string
{
    return basename((string) $response->headers->get('Location'));
}

beforeEach(function () {
    config(['app.landing.enabled' => true, 'app.landing.hosted' => true]);
    demoSetting(true);
});

it('shows the page with the industries, the promise and the form', function () {
    $this->get(route('demo.show'))
        ->assertOk()
        ->assertSee('Prova NONOX på ett påhittat företag.')
        ->assertSee('demons uppgifter följer inte med', false)
        ->assertSee('name="website"', false)
        ->assertSee('name="started"', false);

    foreach (DemoCatalog::industries() as $name) {
        $this->get(route('demo.show'))->assertSee($name);
    }
});

it('offers only the single-company industries', function () {
    expect(array_keys(DemoCatalog::industries()))->not->toContain('for-redovisningsbyraer')->not->toContain('for-ekonomikonsulter')
        ->and(DemoCatalog::industries())->not->toBe([]);
});

it('is on by default for a hosted installation and off for the rest, and /hq can change it', function () {
    demoSetting(null);

    config(['app.landing.hosted' => true]);
    InstanceSettings::current()->forget();
    expect(DemoMode::enabled())->toBeTrue();

    config(['app.landing.hosted' => false]);
    InstanceSettings::current()->forget();
    expect(DemoMode::enabled())->toBeFalse();

    config(['nonox.hq_admins' => ['hq@example.test']]);
    $admin = User::factory()->create(['email' => 'hq@example.test']);

    expect((new UpdateInstanceSettings)($admin, ['operator_company_id' => null, 'waitlist_open' => true, 'registration_open' => false, 'demo_enabled' => true]))->toBe([InstanceSettings::DEMO_ENABLED])
        ->and(DemoMode::enabled())->toBeTrue();
});

it('reads the switch from the main database also while the demo database is the default', function () {
    demoSetting(false);

    DemoMode::onDemoConnection(fn () => expect(DemoMode::enabled())->toBeFalse());

    demoSetting(true);

    DemoMode::onDemoConnection(fn () => expect(DemoMode::enabled())->toBeTrue());
});

it('answers 404 and shows no link while the feature is switched off', function () {
    demoSetting(false);

    $this->get(route('demo.show'))->assertNotFound();
    startDemo($this)->assertNotFound();
    $this->get(route('demo.building', str_repeat('a', 40)))->assertNotFound();
    $this->get(route('demo.open', str_repeat('a', 40)))->assertNotFound();
    $this->get('/')->assertOk()->assertDontSee('data-try-link', false);
});

it('links to the page from the landing page for visitors, with Swedish text and URL', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('data-try-link', false)
        ->assertSee('Prova utan att registrera')
        ->assertSee(url('/prova'), false);

    expect(route('demo.show', absolute: false))->toBe('/prova');

    [$member] = Bookkeeping::company();
    $this->actingAs($member)->get('/')->assertDontSee('data-try-link', false);
});

it('starts a build in the demo queue, asks the browser to wait, and lets the guest in when it is ready', function () {
    Queue::fake();

    $response = startDemo($this);
    $token = tokenOf($response);
    $response->assertRedirect(route('demo.building', $token));

    Queue::assertPushed(BuildDemoCompany::class, fn (BuildDemoCompany $job): bool => $job->industry === 'bokforing-for-cafeer' && $job->connection === 'demo');

    $this->get(route('demo.building', $token))->assertOk()->assertSee('Vi bygger ditt demoföretag.')->assertSee('http-equiv="refresh"', false);
    $this->assertGuest();

    // The worker finishes: a guest with a company, in the demo database.
    [$guest] = Demo::company();
    DemoSessions::markReady($token, $guest);

    $this->get(route('demo.building', $token))->assertRedirect(route('demo.open', $token));

    $opened = $this->get(route('demo.open', $token));
    $opened->assertRedirect(route('dashboard'))->assertCookie(DemoMode::COOKIE);

    // The token worked once.
    expect(DemoSessions::state($token))->toBeNull();
    $this->get(route('demo.open', $token))->assertRedirect(route('demo.show'));
});

it('sends the visitor back with a message when the build failed', function () {
    Queue::fake();
    $token = tokenOf(startDemo($this));
    DemoSessions::markFailed($token);

    $this->get(route('demo.building', $token))->assertRedirect(route('demo.show'));
    $this->get(route('demo.show'))->assertSee('Det gick inte att bygga demon');
});

it('does not open a demo that has ended, or one nobody asked for', function () {
    [$guest] = Demo::company(CarbonImmutable::now()->subMinute());
    $token = Demo::token($guest);

    $this->get(route('demo.open', $token))->assertRedirect(route('demo.show'));
    $this->get(route('demo.open', str_repeat('b', 40)))->assertRedirect(route('demo.show'));
    $this->get(route('demo.building', str_repeat('b', 40)))->assertRedirect(route('demo.show'));
});

it('refuses a form with the honeypot filled in', function () {
    Queue::fake();

    startDemo($this, ['website' => 'https://spam.example'])->assertSessionHasErrors('industry');

    Queue::assertNothingPushed();
});

it('refuses a form sent faster than a person, or never opened, or too old', function () {
    Queue::fake();

    startDemo($this, ['started' => Crypt::encryptString((string) time())])->assertSessionHasErrors('industry');
    startDemo($this, ['started' => 'inte-krypterat'])->assertSessionHasErrors('industry');
    startDemo($this, ['started' => Crypt::encryptString((string) (time() - 7200))])->assertSessionHasErrors('industry');
    startDemo($this, ['started' => null])->assertSessionHasErrors('industry');

    expect((int) config('nonox.demo.min_seconds'))->toBeGreaterThan(0);
    Queue::assertNothingPushed();
});

it('refuses an unknown industry and one that is an accounting firm', function () {
    Queue::fake();

    startDemo($this, ['industry' => 'finns-inte'])->assertSessionHasErrors('industry');
    startDemo($this, ['industry' => 'for-redovisningsbyraer'])->assertSessionHasErrors('industry');

    Queue::assertNothingPushed();
});

it('limits the starts per IP address and hour, and the refused attempts too', function () {
    Queue::fake();
    $max = (int) config('nonox.demo.max_per_ip_per_hour');

    for ($i = 0; $i < $max; $i++) {
        startDemo($this)->assertRedirect();
    }

    startDemo($this)->assertSessionHasErrors('industry');
    Queue::assertPushed(BuildDemoCompany::class, $max);

    // Another address is counted on its own.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
    startDemo($this)->assertRedirect();

    // Too many refused attempts end in 429.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);

    for ($i = 0; $i < 10; $i++) {
        startDemo($this, ['website' => 'x']);
    }

    startDemo($this)->assertStatus(429);
});

it('keeps the visitor\'s address out of the main database, and out of the cache keys in clear', function () {
    Queue::fake();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77']);

    startDemo($this)->assertRedirect();

    $hashed = hash_hmac('sha256', '203.0.113.77', (string) config('app.key'));

    expect(DemoSessions::limiter()->attempts('demo-start:'.$hashed))->toBe(1)
        ->and(DemoSessions::limiter()->attempts('demo-start:203.0.113.77'))->toBe(0)
        ->and(DB::connection('demo')->table('cache')->where('key', 'like', '%203.0.113.77%')->exists())->toBeFalse()
        ->and(Schema::connection(DemoMode::mainConnection())->hasTable('cache'))->toBeFalse();
});

it('keeps every trace of a start out of the main database', function () {
    Queue::fake();
    $mainSessions = DB::table('sessions')->count();

    startDemo($this)->assertRedirect();

    expect(DB::table('audit_logs')->where('action', 'like', 'demo%')->exists())->toBeFalse()
        ->and(DB::table('users')->where('email', 'like', '%@'.DemoMode::GUEST_DOMAIN)->exists())->toBeFalse()
        ->and(DB::table('sessions')->count())->toBe($mainSessions)
        ->and(DB::connection('demo')->table('cache')->count())->toBeGreaterThan(0);
});

it('says the demo is full when the ceiling is reached', function () {
    Queue::fake();
    config(['nonox.demo.max_live' => DemoSessions::live() + 1]);

    startDemo($this)->assertRedirect();
    startDemo($this)->assertSessionHasErrors('industry');

    Queue::assertPushed(BuildDemoCompany::class, 1);
});

it('counts demos that are live towards the ceiling, and not ended ones', function () {
    Demo::company();
    Demo::company(CarbonImmutable::now()->subHour());

    $live = DemoSessions::live();

    expect($live)->toBe(1);

    DB::connection('demo')->table('demo_sessions')->update(['expires_at' => CarbonImmutable::now()->subHour()]);

    expect(DemoSessions::live())->toBe(0);
});

it('starts nothing while the demo database waits to be built again', function () {
    Queue::fake();
    DemoDatabase::startDraining();

    startDemo($this)->assertSessionHasErrors('industry');

    Queue::assertNothingPushed();
});

it('sends a signed-in visitor to the dashboard instead of the form', function () {
    [$member] = Bookkeeping::company();

    $this->actingAs($member)->get(route('demo.show'))->assertRedirect(route('dashboard'));
    $this->actingAs($member)->post(route('demo.start'), [])->assertRedirect(route('dashboard'));
});
