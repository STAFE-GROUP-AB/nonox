<?php

declare(strict_types=1);

use App\Actions\Hq\UpdateInstanceSettings;
use App\Actions\Waitlist\JoinWaitlist;
use App\Livewire\Auth\LoginForm;
use App\Livewire\Hq\Settings;
use App\Livewire\Waitlist\JoinForm;
use App\Mail\LoginCodeMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\InstanceSetting;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Instance\InstanceSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Inställningar in /hq (ADR-044): only HQ admins, every change audited
 * with ids and booleans, and the two switches: the queue (open by default)
 * and self-registration (closed by default, ADR-038).
 */

function hqSetting(string $key, mixed $value): void
{
    InstanceSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    InstanceSettings::current()->forget();
}

beforeEach(function () {
    Mail::fake();
    config(['nonox.hq_admins' => ['hq@example.test'], 'app.admin_email' => null, 'app.landing.enabled' => true]);
    $this->admin = User::factory()->withCompany()->create(['email' => 'hq@example.test']);
});

it('answers 404 to everyone but an HQ admin, also on Livewire requests', function () {
    $this->get(route('hq.settings'))->assertNotFound();

    $member = User::factory()->withCompany()->create();
    $this->actingAs($member)->get(route('hq.settings'))->assertNotFound();
    Livewire::test(Settings::class)->assertStatus(404);

    expect(fn () => app(UpdateInstanceSettings::class)($member, ['waitlist_open' => true, 'registration_open' => true]))
        ->toThrow(NotFoundHttpException::class);

    $this->actingAs($this->admin)->get(route('hq.settings'))->assertOk()
        ->assertSee('Driftbolag')
        ->assertSee('Kön är öppen')
        ->assertSee('Självregistrering är öppen')
        ->assertSee('href="'.route('hq.settings').'"', false);
});

it('has today\'s behaviour by default: queue open, self-registration closed, no operator company', function () {
    $settings = InstanceSettings::current();

    expect($settings->waitlistOpen())->toBeTrue()
        ->and($settings->selfRegistrationOpen())->toBeFalse()
        ->and($settings->operatorCompanyId())->toBeNull()
        ->and($settings->customersCompanyId())->toBeNull()
        ->and($settings->waitlistCompanyId())->toBeNull();
});

it('saves the settings with a passkey step and audits ids and booleans only', function () {
    $operator = $this->admin->currentCompany();

    $this->actingAs($this->admin);
    // A passkey answer given a minute ago (HqAccess::confirm) still covers the decision.
    session()->put('hq.step_up', ['user_id' => $this->admin->id, 'at' => now()->subMinute()->getTimestamp()]);

    Livewire::test(Settings::class)
        ->set('operatorCompanyId', (string) $operator->id)
        ->set('waitlistOpen', false)
        ->set('registrationOpen', true)
        ->call('save', null)
        ->assertHasNoErrors();

    $settings = InstanceSettings::current();
    $settings->forget();
    $audit = AuditLog::query()->where('action', 'hq.settings_updated')->sole();

    expect($settings->operatorCompanyId())->toBe($operator->id)
        ->and($settings->customersCompanyId())->toBe($operator->id)
        ->and($settings->waitlistCompanyId())->toBe($operator->id)
        ->and($settings->waitlistOpen())->toBeFalse()
        ->and($settings->selfRegistrationOpen())->toBeTrue()
        ->and($audit->company_id)->toBeNull()
        ->and($audit->user_id)->toBe($this->admin->id)
        ->and($audit->payload['after'])->toEqual([InstanceSettings::OPERATOR => $operator->id, InstanceSettings::WAITLIST_OPEN => false, InstanceSettings::REGISTRATION_OPEN => true])
        ->and(json_encode($audit->payload))->not->toContain($operator->name);
});

it('refuses decisions without a fresh passkey confirmation', function () {
    $this->actingAs($this->admin);

    Livewire::test(Settings::class)
        ->set('registrationOpen', true)
        ->call('save', null)
        ->assertHasErrors('passkey');

    expect(InstanceSettings::current()->selfRegistrationOpen())->toBeFalse();
});

it('refuses an archived or test company as operator', function () {
    $archived = Company::factory()->create(['archived_at' => now()]);
    $test = Company::factory()->create(['is_test_company' => true]);

    foreach ([$archived, $test] as $company) {
        expect(fn () => app(UpdateInstanceSettings::class)($this->admin, ['operator_company_id' => $company->id, 'waitlist_open' => true, 'registration_open' => false]))
            ->toThrow(ValidationException::class);
    }

    expect(InstanceSettings::current()->operatorCompanyId())->toBeNull();
});

it('closes the queue: /ko says so without a form, nothing is taken in, and the calls to action adapt', function () {
    hqSetting(InstanceSettings::WAITLIST_OPEN, false);

    $this->get('/ko')->assertOk()
        ->assertSee('data-waitlist-closed', false)
        ->assertSee('Kön är stängd just nu.')
        ->assertDontSee('wire:submit="submit"', false);

    Livewire::test(JoinForm::class)
        ->set('name', 'Sen Sökande')
        ->set('email', 'sen@example.test')
        ->call('submit')
        ->assertHasErrors('form');

    expect(fn () => app(JoinWaitlist::class)(['name' => 'X', 'email' => 'x@example.test', 'company_name' => 'X AB', 'industry' => 'bokforing-for-konsulter', 'employees' => '1-4', 'current_system' => 'inget', 'consent' => true]))
        ->toThrow(ValidationException::class);

    expect(WaitlistEntry::query()->count())->toBe(0);

    $this->get('/')->assertOk()->assertSee('Om betan')->assertDontSee('Ställ dig i kö');
});

it('keeps the queue open by default with the form', function () {
    $this->get('/ko')->assertOk()
        ->assertDontSee('data-waitlist-closed', false)
        ->assertSee('Ställ dig i kö till NONOX.');
});

it('lets an unknown address create an account while self-registration is open', function () {
    hqSetting(InstanceSettings::REGISTRATION_OPEN, true);

    $this->get('/')->assertOk()->assertSee('Kom igång')->assertSee('href="'.route('login').'"', false);

    $component = Livewire::test(LoginForm::class)
        ->set('email', 'Ny@Example.test')
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('step', 'code')
        ->assertSee('Vi har skickat en kod med åtta siffror till');

    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return $mail->hasTo('ny@example.test');
    });

    $component->set('code', $code)->call('verify')->assertSet('step', 'name');

    expect(User::query()->where('email', 'ny@example.test')->exists())->toBeFalse();

    $component->set('name', 'Nya Användaren')->call('createAccount')->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'ny@example.test')->sole();
    expect($user->name)->toBe('Nya Användaren')->and($user->email_verified_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('checks again at the name step: closing self-registration meanwhile creates no account', function () {
    hqSetting(InstanceSettings::REGISTRATION_OPEN, true);

    $component = Livewire::test(LoginForm::class)->set('email', 'sen@example.test')->call('sendCode');

    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    $component->set('code', $code)->call('verify')->assertSet('step', 'name');

    hqSetting(InstanceSettings::REGISTRATION_OPEN, false);

    $component->set('name', 'Sen')->call('createAccount')->assertSet('step', 'email');

    expect(User::query()->where('email', 'sen@example.test')->exists())->toBeFalse();
});

it('sends no code to an unknown address while self-registration is closed', function () {
    Livewire::test(LoginForm::class)
        ->set('email', 'okand@example.test')
        ->call('sendCode')
        ->assertSet('step', 'code')
        ->assertSee('har ett konto eller en inbjudan');

    Mail::assertNothingSent();
    expect(User::query()->where('email', 'okand@example.test')->exists())->toBeFalse();
});
