<?php

declare(strict_types=1);

use App\Actions\Auth\SendLoginCode;
use App\Actions\Auth\VerifyLoginCode;
use App\Livewire\Auth\LoginForm;
use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();
});

/**
 * Sends a code and returns the plain code taken from the outgoing email.
 */
function sendCodeTo(string $email): string
{
    app(SendLoginCode::class)($email);

    $code = null;

    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return (string) $code;
}

it('emails a six-digit code and stores only its hash', function () {
    $code = sendCodeTo('anna@example.com');

    expect($code)->toMatch('/^\d{6}$/');

    $stored = LoginCode::where('email', 'anna@example.com')->sole();

    expect($stored->code_hash)->not->toBe($code)
        ->and($stored->code_hash)->toBe(LoginCode::hash($code));

    Mail::assertSent(LoginCodeMail::class, fn (LoginCodeMail $mail) => $mail->hasTo('anna@example.com')
        && $mail->envelope()->subject === "Din inloggningskod: {$code}");
});

it('sends at most one email when the code is requested twice within the cooldown', function () {
    $send = app(SendLoginCode::class);

    $send('anna@example.com');
    $send('anna@example.com');
    $send('ANNA@example.com ');

    Mail::assertSentCount(1);
    expect(LoginCode::where('email', 'anna@example.com')->count())->toBe(1);
});

it('sends a new code after the cooldown and retires the old one', function () {
    $first = sendCodeTo('anna@example.com');

    $this->travel(61)->seconds();
    app(SendLoginCode::class)('anna@example.com');

    Mail::assertSentCount(2);
    expect(app(VerifyLoginCode::class)('anna@example.com', $first))->toBeFalse();
});

it('accepts the right code once', function () {
    $code = sendCodeTo('anna@example.com');
    $verify = app(VerifyLoginCode::class);

    expect($verify('anna@example.com', $code))->toBeTrue()
        ->and($verify('anna@example.com', $code))->toBeFalse();
});

it('rejects an expired code', function () {
    $code = sendCodeTo('anna@example.com');

    $this->travel(11)->minutes();

    expect(app(VerifyLoginCode::class)('anna@example.com', $code))->toBeFalse();
});

it('locks the code after five wrong attempts, even for the right code', function () {
    $code = sendCodeTo('anna@example.com');
    $wrong = $code === '000000' ? '111111' : '000000';
    $verify = app(VerifyLoginCode::class);

    foreach (range(1, 5) as $attempt) {
        expect($verify('anna@example.com', $wrong))->toBeFalse();
    }

    expect($verify('anna@example.com', $code))->toBeFalse();
});

it('does not accept a code sent to another address', function () {
    $code = sendCodeTo('anna@example.com');

    expect(app(VerifyLoginCode::class)('eve@example.com', $code))->toBeFalse();
});

it('signs in an existing user with the emailed code', function () {
    $user = User::factory()->withCompany()->create(['email' => 'anna@example.com']);

    $component = Livewire::test(LoginForm::class)
        ->set('email', 'Anna@Example.com')
        ->call('sendCode')
        ->assertSet('step', 'code');

    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    $component->set('code', $code)
        ->call('verify')
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('shows a Swedish error for a wrong code and stays signed out', function () {
    User::factory()->create(['email' => 'anna@example.com']);

    $component = Livewire::test(LoginForm::class)
        ->set('email', 'anna@example.com')
        ->call('sendCode');

    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    $component->set('code', $code === '123456' ? '654321' : '123456')
        ->call('verify')
        ->assertHasErrors('code')
        ->assertSee('Koden stämmer inte');

    $this->assertGuest();
});

it('creates a verified account for a new address and sends it to /kom-igang', function () {
    $component = Livewire::test(LoginForm::class)
        ->set('email', 'ny@example.com')
        ->call('sendCode');

    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    $component->set('code', $code)
        ->call('verify')
        ->assertSet('step', 'name')
        ->set('name', 'Ny Person')
        ->call('createAccount')
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'ny@example.com')->sole();

    expect($user->name)->toBe('Ny Person')
        ->and($user->email_verified_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);
    $this->get(route('dashboard'))->assertRedirect(route('onboarding'));
});

it('skips the name step when an invitation already carries the name', function () {
    $owner = User::factory()->withCompany()->create();
    $owner->currentCompany()->teamInvitations()->create(['email' => 'linda@example.test', 'role' => 'admin', 'name' => 'Linda Exempel']);

    $component = Livewire::test(LoginForm::class)
        ->set('email', 'Linda@Example.test')
        ->call('sendCode');

    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    $component->set('code', $code)
        ->call('verify')
        ->assertNotSet('step', 'name')
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs(User::where('email', 'linda@example.test')->sole());
    expect(User::where('email', 'linda@example.test')->sole()->name)->toBe('Linda Exempel');
});

it('creates the account for the verified address even if the email field is tampered with', function () {
    $component = Livewire::test(LoginForm::class)
        ->set('email', 'ny@example.com')
        ->call('sendCode');

    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    $component->set('code', $code)
        ->call('verify')
        ->set('email', 'victim@example.com')
        ->set('name', 'Mallory')
        ->call('createAccount');

    expect(User::where('email', 'victim@example.com')->exists())->toBeFalse()
        ->and(User::where('email', 'ny@example.com')->exists())->toBeTrue();
});

it('refuses to skip straight to account creation without a verified code', function () {
    Livewire::test(LoginForm::class)
        ->set('email', 'victim@example.com')
        ->set('name', 'Mallory')
        ->call('createAccount')
        ->assertSet('step', 'email');

    expect(User::where('email', 'victim@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

it('signs in with one click when local autofill is on', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['auth.login_codes.autofill' => true, 'app.admin_email' => 'admin@example.com']);
    $admin = User::factory()->withCompany()->create(['email' => 'admin@example.com']);

    Livewire::test(LoginForm::class)
        ->assertSet('email', 'admin@example.com')
        ->call('sendCode')
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($admin);
});

it('never autofills outside the local environment', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);
    config(['auth.login_codes.autofill' => true, 'app.admin_email' => 'admin@example.com']);
    User::factory()->create(['email' => 'admin@example.com']);

    Livewire::test(LoginForm::class)
        ->assertSet('email', '')
        ->set('email', 'admin@example.com')
        ->call('sendCode')
        ->assertSet('step', 'code')
        ->assertSet('code', '')
        ->assertNoRedirect();

    $this->assertGuest();
})->with(['production', 'staging', 'testing']);

it('logs out on /logga-ut', function () {
    $this->actingAs(User::factory()->withCompany()->create());

    $this->post(route('logout'))->assertRedirect('/');

    $this->assertGuest();
    expect(route('logout', absolute: false))->toBe('/logga-ut');
});

it('sends guests to /logga-in', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));

    $this->get('/logga-in')->assertOk()->assertSee('Skicka kod');
    $this->get('/registrera')->assertRedirect('/logga-in');
});
