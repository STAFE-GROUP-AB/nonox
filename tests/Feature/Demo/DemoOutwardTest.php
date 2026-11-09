<?php

declare(strict_types=1);

use App\Actions\Auth\SendLoginCode;
use App\Actions\Company\CreateCompany;
use App\Actions\Jetstream\InviteTeamMember;
use App\Actions\Payables\CreatePaymentBatch;
use App\Models\Company;
use App\Models\LoginCode;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Modules\Ai\AiModule;
use App\Modules\Crm\CrmModule;
use App\Modules\Inbox\InboxModule;
use App\Modules\ModuleManager;
use App\Support\Demo\DemoMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Bookkeeping;
use Tests\Support\Demo;

/*
 * Nothing leaves the building from the demo database (ADR-077): e-mail,
 * Peppol, bank, Skatteverket, payment files, API tokens, invitations and AI
 * are shut for work on the demo connection, while the main database is
 * untouched.
 */

beforeEach(fn () => Demo::enable());

/**
 * A demo company with a guest as its owner and some modules on, made while the demo is being built.
 *
 * @return array{0: User, 1: Company}
 */
function openDemo(): array
{
    [$guest, $company] = Demo::company();

    DemoMode::building(function () use ($company, $guest) {
        $modules = app(ModuleManager::class);
        $modules->enable(AiModule::KEY, $company, $guest);
        $modules->enable(InboxModule::KEY, $company, $guest);
        $modules->enable(CrmModule::KEY, $company, $guest);
    });

    return [$guest, $company];
}

it('turns off the modules that talk to the outside world in the demo database, and only there', function () {
    [, $demo] = openDemo();
    [$ownerOfReal, $real] = Bookkeeping::company();
    $modules = app(ModuleManager::class);
    $modules->enable(AiModule::KEY, $real, $ownerOfReal);
    $modules->enable(InboxModule::KEY, $real, $ownerOfReal);

    DemoMode::onDemoConnection(function () use ($modules, $demo) {
        expect($modules->isEnabled(AiModule::KEY, $demo))->toBeFalse()
            ->and($modules->isEnabled(InboxModule::KEY, $demo))->toBeFalse()
            ->and($modules->isEnabled(CrmModule::KEY, $demo))->toBeTrue();

        foreach (['peppol', 'banking', 'connect', 'pos'] as $key) {
            expect(DemoMode::moduleBlocked($key))->toBeTrue();
        }

        // While the demo is built they are on.
        expect(DemoMode::building(fn (): bool => $modules->isEnabled(AiModule::KEY, $demo)))->toBeTrue();
    });

    expect($modules->isEnabled(AiModule::KEY, $real))->toBeTrue()
        ->and($modules->isEnabled(InboxModule::KEY, $real))->toBeTrue()
        ->and(DemoMode::moduleBlocked('ai'))->toBeFalse();
});

it('refuses every HTTP request on the demo connection', function () {
    Http::fake();

    expect(Http::get('https://example.test/real')->status())->toBe(200);

    DemoMode::onDemoConnection(function () {
        expect(fn () => Http::get('https://example.test/demo'))->toThrow(ConnectionException::class);

        // The build itself is not blocked.
        expect(DemoMode::building(fn (): int => Http::get('https://example.test/building')->status()))->toBe(200);
    });

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/demo'));
});

it('sends no mail on the demo connection, and none to a guest address', function () {
    $count = fn (): int => count(app('mailer')->getSymfonyTransport()->messages());
    $before = $count();

    Mail::raw('Hej', fn ($message) => $message->to('kund@example.test')->subject('Före'));
    expect($count())->toBe($before + 1);

    Mail::raw('Hej', fn ($message) => $message->to('gast-abc@demo.invalid')->subject('Till gäst'));
    expect($count())->toBe($before + 1);

    DemoMode::onDemoConnection(function () use ($count, $before) {
        Mail::raw('Hej', fn ($message) => $message->to('kund@example.test')->subject('Under demon'));
        expect($count())->toBe($before + 1);
    });
});

it('does not invite anyone from a demo', function () {
    [$guest, $demo] = openDemo();
    Mail::fake();

    DemoMode::onDemoConnection(function () use ($guest, $demo) {
        expect(fn () => app(InviteTeamMember::class)->invite($guest, $demo, 'ny@example.test', 'admin'))->toThrow(ValidationException::class);

        expect(TeamInvitation::query()->where('team_id', $demo->id)->count())->toBe(0);
    });

    Mail::assertNothingSent();
});

it('makes no payment file in a demo', function () {
    [$guest, $demo] = openDemo();

    $thrown = DemoMode::onDemoConnection(function () use ($guest, $demo) {
        try {
            app(CreatePaymentBatch::class)($guest, $demo, [], CarbonImmutable::today()->addDay(), true);
        } catch (ValidationException $exception) {
            return $exception;
        }

        return null;
    });

    expect($thrown?->errors())->toHaveKey('batch')
        ->and($thrown->errors()['batch'][0])->toBe(DemoMode::message());
});

it('gives no API token in the demo database', function () {
    [$guest] = openDemo();

    DemoMode::onDemoConnection(function () use ($guest) {
        $before = PersonalAccessToken::query()->count();
        $guest->createToken('försök');

        expect(PersonalAccessToken::query()->count())->toBe($before);
    });

    $real = User::factory()->create();
    $before = PersonalAccessToken::query()->count();
    $real->createToken('riktig');

    expect(PersonalAccessToken::query()->count())->toBe($before + 1);
});

it('keeps a guest out of the profile and from making more companies', function () {
    [$guest] = openDemo();
    $token = Demo::token($guest);
    $this->get(route('demo.open', $token))->assertRedirect(route('dashboard'));
    $this->withCookie(DemoMode::COOKIE, Demo::sessionId());

    $this->get(route('profile.show'))->assertRedirect(route('dashboard'));
    $this->get(route('teams.create'))->assertRedirect(route('dashboard'));

    DemoMode::onDemoConnection(fn () => expect(fn () => app(CreateCompany::class)->create($guest, [
        'name' => 'Ett till AB',
        'org_number' => '556036-0793',
        'legal_form' => 'AB',
        'fiscal_year_start_month' => 1,
        'accounting_method' => 'FAKTURAMETODEN',
        'vat_registered' => true,
        'vat_period' => 'QUARTERLY',
        'reporting_framework' => 'K2',
        'framework_confirmed' => true,
        'f_skatt' => true,
        'employer_registered' => false,
    ]))->toThrow(ValidationException::class));

    // A signed-in real user, on the main database, still reaches the profile.
    $this->defaultCookies = [];
    [$member] = Bookkeeping::company();
    $this->actingAs($member)->get(route('profile.show'))->assertOk();
});

it('sends no sign-in code to a guest\'s placeholder address', function () {
    Mail::fake();

    app(SendLoginCode::class)('gast-abc@demo.invalid');

    expect(LoginCode::query()->where('email', 'gast-abc@demo.invalid')->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('shows the demo banner with the time left on every page of a demo, and not on a real company', function () {
    [$guest] = openDemo();
    $this->get(route('demo.open', Demo::token($guest)))->assertRedirect(route('dashboard'));

    $this->withCookie(DemoMode::COOKIE, Demo::sessionId())->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-demo-banner', false)
        ->assertSee('Allt du gör försvinner')
        ->assertSee('demons uppgifter följer inte med');

    $this->defaultCookies = [];
    [$member] = Bookkeeping::company();

    $this->actingAs($member)->get(route('dashboard'))->assertDontSee('data-demo-banner', false);
});

it('ends a guest\'s session when the time is up', function () {
    [$guest] = openDemo();
    $this->get(route('demo.open', Demo::token($guest)))->assertRedirect(route('dashboard'));
    $session = Demo::sessionId();

    DB::connection('demo')->table('demo_sessions')->where('user_id', $guest->id)->update(['expires_at' => CarbonImmutable::now()->subMinute()]);
    $this->withCookie(DemoMode::COOKIE, $session)->get(route('dashboard'))->assertRedirect(route('login'));
});
