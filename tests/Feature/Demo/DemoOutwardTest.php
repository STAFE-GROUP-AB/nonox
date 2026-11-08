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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Bookkeeping;

/*
 * Nothing leaves the building from a demo (ADR-077): e-mail, Peppol, bank,
 * Skatteverket, payment files, API tokens, invitations and AI are shut for a
 * demo company and its guest, while a real company is untouched.
 */

/**
 * A demo company with a guest as its owner, flagged the way CreateDemo does.
 *
 * @return array{0: User, 1: Company}
 */
function openDemo(): array
{
    [$owner, $company] = Bookkeeping::company();

    $modules = app(ModuleManager::class);
    $modules->enable(AiModule::KEY, $company, $owner);
    $modules->enable(InboxModule::KEY, $company, $owner);
    $modules->enable(CrmModule::KEY, $company, $owner);

    $at = CarbonImmutable::now()->addHours(2);
    $company->forceFill(['is_test_company' => true, 'demo_expires_at' => $at])->save();
    $owner->forceFill(['demo_expires_at' => $at])->save();

    return [$owner->fresh(), $company->fresh()];
}

it('turns off the modules that talk to the outside world, and only for a demo', function () {
    [$guest, $demo] = openDemo();
    [$ownerOfReal, $real] = Bookkeeping::company();
    $modules = app(ModuleManager::class);
    $modules->enable(AiModule::KEY, $real, $ownerOfReal);
    $modules->enable(InboxModule::KEY, $real, $ownerOfReal);

    expect($modules->isEnabled(AiModule::KEY, $demo))->toBeFalse()
        ->and($modules->isEnabled(InboxModule::KEY, $demo))->toBeFalse()
        ->and($modules->isEnabled(CrmModule::KEY, $demo))->toBeTrue()
        ->and($modules->isEnabled(AiModule::KEY, $real))->toBeTrue()
        ->and($modules->isEnabled(InboxModule::KEY, $real))->toBeTrue()
        ->and(DemoMode::seeding(fn (): bool => $modules->isEnabled(AiModule::KEY, $demo)))->toBeTrue();

    foreach (['peppol', 'banking', 'connect', 'pos'] as $key) {
        expect(DemoMode::moduleBlocked($key, $demo->id))->toBeTrue()
            ->and(DemoMode::moduleBlocked($key, $real->id))->toBeFalse();
    }
});

it('refuses every HTTP request while a demo company is the current company', function () {
    [$guest] = openDemo();
    [$member] = Bookkeeping::company();
    Http::fake();

    $this->actingAs($member);
    expect(Http::get('https://example.test/real')->status())->toBe(200);

    $this->actingAs($guest);
    expect(fn () => Http::get('https://example.test/demo'))->toThrow(ConnectionException::class);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/demo'));

    // The build itself is not blocked.
    expect(DemoMode::seeding(fn (): int => Http::get('https://example.test/building')->status()))->toBe(200);
});

it('sends no mail while a demo is open, and none to a guest address', function () {
    [$guest] = openDemo();
    $transport = fn () => app('mailer')->getSymfonyTransport();
    $count = fn (): int => count($transport()->messages());
    $before = $count();

    Mail::raw('Hej', fn ($message) => $message->to('kund@example.test')->subject('Före'));
    expect($count())->toBe($before + 1);

    Mail::raw('Hej', fn ($message) => $message->to('gast-abc@demo.invalid')->subject('Till gäst'));
    expect($count())->toBe($before + 1);

    $this->actingAs($guest);
    Mail::raw('Hej', fn ($message) => $message->to('kund@example.test')->subject('Under demon'));
    expect($count())->toBe($before + 1);
});

it('does not invite anyone from a demo', function () {
    [$guest, $demo] = openDemo();
    Mail::fake();

    expect(fn () => app(InviteTeamMember::class)->invite($guest, $demo, 'ny@example.test', 'admin'))->toThrow(ValidationException::class);

    expect(TeamInvitation::query()->where('team_id', $demo->id)->count())->toBe(0);
    Mail::assertNothingSent();
});

it('makes no payment file in a demo', function () {
    [$guest, $demo] = openDemo();

    try {
        app(CreatePaymentBatch::class)($guest, $demo, [], CarbonImmutable::today()->addDay(), true);
        $thrown = null;
    } catch (ValidationException $exception) {
        $thrown = $exception;
    }

    expect($thrown?->errors())->toHaveKey('batch')
        ->and($thrown->errors()['batch'][0])->toBe(DemoMode::message());
});

it('gives a guest no API token', function () {
    [$guest] = openDemo();
    $before = PersonalAccessToken::query()->count();

    $guest->createToken('försök');

    expect(PersonalAccessToken::query()->count())->toBe($before);

    $real = User::factory()->create();
    $real->createToken('riktig');

    expect(PersonalAccessToken::query()->count())->toBe($before + 1);
});

it('keeps a guest out of the profile and from making more companies', function () {
    [$guest] = openDemo();

    $this->actingAs($guest)->get(route('profile.show'))->assertRedirect(route('dashboard'));
    $this->actingAs($guest)->get(route('teams.create'))->assertRedirect(route('dashboard'));

    expect(fn () => app(CreateCompany::class)->create($guest, [
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
    ]))->toThrow(ValidationException::class);

    // A signed-in real user still reaches both.
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
    [$member] = Bookkeeping::company();

    $this->actingAs($guest)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-demo-banner', false)
        ->assertSee('Allt du gör försvinner')
        ->assertSee('demons uppgifter följer inte med');

    $this->actingAs($member)->get(route('dashboard'))->assertDontSee('data-demo-banner', false);
});

it('ends a guest\'s session when the time is up', function () {
    [$guest] = openDemo();
    $guest->forceFill(['demo_expires_at' => CarbonImmutable::now()->subMinute()])->save();

    config(['nonox.demo.hours' => 2, 'app.landing.hosted' => true]);

    $this->actingAs($guest)->get(route('dashboard'))->assertRedirect(route('demo.show'));
    $this->assertGuest();
});
