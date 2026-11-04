<?php

declare(strict_types=1);

use App\Actions\Auth\SendLoginCode;
use App\Actions\Auth\VerifyLoginCode;
use App\Livewire\Auth\LoginForm;
use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Auth\ClientAddressBucket;
use App\Support\LoginCodeAutofill;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
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

it('emails an eight-digit code and stores only its hash', function () {
    $code = sendCodeTo('anna@example.com');

    expect($code)->toMatch('/^\d{8}$/');

    $stored = LoginCode::where('email', 'anna@example.com')->sole();

    expect($stored->code_hash)->not->toBe($code)
        ->and($stored->code_hash)->toBe(LoginCode::hash($code));

    Mail::assertSent(LoginCodeMail::class, fn (LoginCodeMail $mail) => $mail->hasTo('anna@example.com')
        && $mail->envelope()->subject === 'Din inloggningskod' && ! str_contains($mail->envelope()->subject, $code));
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

    $component->set('code', $code === '12345678' ? '87654321' : '12345678')
        ->call('verify')
        ->assertHasErrors('code')
        ->assertSee('Koden stämmer inte');

    $this->assertGuest();
});

it('sends no code and creates no account for an unknown address, and answers exactly as for a known one (ADR-038)', function () {
    User::factory()->withCompany()->create(['email' => 'finns@example.com']);

    $unknown = Livewire::test(LoginForm::class)->set('email', 'okand@example.com')->call('sendCode')->assertHasNoErrors()->assertSet('step', 'code');
    Mail::assertNothingOutgoing();

    $known = Livewire::test(LoginForm::class)->set('email', 'finns@example.com')->call('sendCode')->assertSet('step', 'code');
    Mail::assertSentCount(1);

    $neutral = fn (string $html, string $email): string => str_replace($email, 'ADRESS', preg_replace('/wire:[a-z:.]+="[^"]*"|wire:id="[^"]*"|wire:snapshot="[^"]*"|wire:effects="[^"]*"/', '', $html) ?? '');

    expect($neutral($unknown->html(), 'okand@example.com'))->toBe($neutral($known->html(), 'finns@example.com'))
        ->and($unknown->html())->toContain('har ett konto eller en inbjudan')
        ->and($unknown->html())->toContain(route('waitlist'));

    $unknown->set('code', '12345678')->call('verify')
        ->assertHasErrors(['code' => 'Koden stämmer inte eller har slutat gälla. Kontrollera mejlet eller skicka en ny kod.']);

    expect(User::query()->byEmail('okand@example.com')->exists())->toBeFalse()
        ->and(LoginCode::query()->where('email', 'okand@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

it('never autofills a code for an unknown address, even locally', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['auth.login_codes.autofill' => true, 'app.url' => 'http://oss.test']);

    Livewire::test(LoginForm::class)->set('email', 'okand@example.com')->call('sendCode')->assertSet('step', 'code')->assertSet('code', '')->assertNoRedirect();

    expect(User::query()->byEmail('okand@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

it('shows the way to the waiting list on the sign-in page', function () {
    config(['app.landing.enabled' => true]);

    $this->get('/logga-in')->assertOk()
        ->assertSee('Inte godkänd än?')
        ->assertSee(route('waitlist'), false)
        ->assertDontSee('Är du ny skapas ett konto åt dig')
        ->assertDontSee('Har du inget konto skapas ett när du loggar in');
});

it('creates a verified account for an invited address without a name and sends it to /kom-igang', function () {
    $owner = User::factory()->withCompany()->create();
    $owner->currentCompany()->teamInvitations()->create(['email' => 'ny@example.com', 'role' => 'bookkeeper']);

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

it('creates no account when the invitation is withdrawn before the name step', function () {
    $owner = User::factory()->withCompany()->create();
    $invitation = $owner->currentCompany()->teamInvitations()->create(['email' => 'ny@example.com', 'role' => 'bookkeeper']);

    $component = Livewire::test(LoginForm::class)->set('email', 'ny@example.com')->call('sendCode');

    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    $component->set('code', $code)->call('verify')->assertSet('step', 'name');

    $invitation->delete();

    $component->set('name', 'Ny Person')->call('createAccount')->assertSet('step', 'email');

    expect(User::query()->byEmail('ny@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

it('sends no code for an expired invitation', function () {
    $owner = User::factory()->withCompany()->create();
    $owner->currentCompany()->teamInvitations()->create(['email' => 'gammal@example.com', 'role' => 'bookkeeper']);
    $this->travel(TeamInvitation::VALID_DAYS + 1)->days();

    Livewire::test(LoginForm::class)->set('email', 'gammal@example.com')->call('sendCode')->assertSet('step', 'code');

    Mail::assertNothingOutgoing();
});

it('creates the account for the verified address even if the email field is tampered with', function () {
    $owner = User::factory()->withCompany()->create();
    $owner->currentCompany()->teamInvitations()->create(['email' => 'ny@example.com', 'role' => 'bookkeeper']);

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
    // PASSKEYS_SKIP_LOCALLY follows OTP_AUTOFILL: the admin is an HQ admin, who otherwise needs a passkey.
    config(['auth.login_codes.autofill' => true, 'auth.passkeys.skip_locally' => true, 'app.admin_email' => 'admin@example.com']);
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
    $this->get('/registrera')->assertRedirect('/ko');
});

it('stops a session after too many wrong codes, without locking the owner out', function () {
    session()->put('login.pending_email', 'offer@example.test');
    RateLimiter::increment(LoginForm::failedKey('offer@example.test'), 3600, LoginForm::MAX_FAILED_PER_HOUR);

    Livewire::test(LoginForm::class)
        ->set('code', '12345678')
        ->call('verify')
        ->assertHasErrors(['code' => 'För många felaktiga koder för den här adressen. Vänta en timme och försök igen.']);

    // The owner signs in from their own browser: someone else's wrong guesses do not count there.
    session()->invalidate();
    session()->put('login.pending_email', 'offer@example.test');

    Livewire::test(LoginForm::class)
        ->set('code', '12345678')
        ->call('verify')
        ->assertHasErrors(['code' => 'Koden stämmer inte eller har slutat gälla. Kontrollera mejlet eller skicka en ny kod.']);
});

it('keeps a code valid when a new one is requested within the cooldown', function () {
    $code = sendCodeTo('anna@example.com');

    $this->travel(30)->seconds();
    app(SendLoginCode::class)('anna@example.com');

    expect(app(VerifyLoginCode::class)('anna@example.com', $code))->toBeTrue();
});

it('sends the code mail after the response, not on the queue, so no answer waits for the mail server', function () {
    $this->withDefer();

    app(SendLoginCode::class)('anna@example.com');

    Mail::assertNothingOutgoing();

    app(DeferredCallbackCollection::class)->invoke();

    Mail::assertSent(LoginCodeMail::class, fn (LoginCodeMail $mail): bool => $mail->hasTo('anna@example.com'));
    expect(new LoginCodeMail('12345678'))->not->toBeInstanceOf(ShouldQueue::class);
});

it('sends the deferred code mail at the end of a real HTTP request', function () {
    $this->withDefer();

    expect(app(HttpKernel::class)->hasMiddleware(InvokeDeferredCallbacks::class))->toBeTrue();

    Route::get('/test-login-code', function (SendLoginCode $sendLoginCode): string {
        $sendLoginCode('anna@example.com');

        return 'ok';
    });

    $this->get('/test-login-code')->assertOk();

    Mail::assertSent(LoginCodeMail::class, fn (LoginCodeMail $mail): bool => $mail->hasTo('anna@example.com'));
});

it('caps the codes sent to one address per day and client address without refusing the owner', function () {
    User::factory()->create(['email' => 'daglig@example.test']);
    RateLimiter::increment(SendLoginCode::dayKey('daglig@example.test', '127.0.0.1'), 86400, SendLoginCode::MAX_SENDS_PER_DAY);

    Livewire::test(LoginForm::class)->set('email', 'daglig@example.test')->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('step', 'code');
    Mail::assertNothingOutgoing();
});

it('does not let one client address use up an address\'s day, but keeps a ceiling across all of them', function () {
    User::factory()->create(['email' => 'offer@example.test']);
    RateLimiter::increment(SendLoginCode::dayKey('offer@example.test', '203.0.113.9'), 86400, SendLoginCode::MAX_SENDS_PER_DAY);

    // The owner, from another connection, still gets a code.
    app(SendLoginCode::class)('offer@example.test', '198.51.100.4');
    Mail::assertSentCount(1);

    // Across all connections the address gets at most MAX_SENDS_PER_DAY_TOTAL.
    RateLimiter::increment(SendLoginCode::dayTotalKey('offer@example.test'), 86400, SendLoginCode::MAX_SENDS_PER_DAY_TOTAL);
    $this->travel(61)->seconds();
    app(SendLoginCode::class)('offer@example.test', '192.0.2.1');
    Mail::assertSentCount(1);
});

it('keeps the latest code valid when someone else exhausts the caps for an address', function () {
    $user = User::factory()->withCompany()->create(['email' => 'offer@example.test']);
    $code = sendCodeTo('offer@example.test');

    // Someone asks for codes in the owner's name, past the cooldown and past the ten-minute cap.
    RateLimiter::increment(SendLoginCode::tenMinuteKey('offer@example.test'), 600, SendLoginCode::MAX_SENDS_PER_TEN_MINUTES);
    $this->travel(61)->seconds();

    foreach (range(1, 3) as $attempt) {
        Livewire::test(LoginForm::class)->set('email', 'offer@example.test')->call('sendCode')
            ->assertHasNoErrors()
            ->assertSet('step', 'code');
    }

    Mail::assertSentCount(1);

    // The owner still signs in with the code in their inbox.
    Livewire::test(LoginForm::class)->set('email', 'offer@example.test')->call('sendCode')
        ->set('code', $code)
        ->call('verify')
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('counts the service-wide cap only for mails sent to addresses without an account', function () {
    // Requests for unknown addresses send nothing while self-registration is closed, and count nothing.
    foreach (range(1, 5) as $attempt) {
        Livewire::test(LoginForm::class)->set('email', "okand{$attempt}@example.test")->call('sendCode')->assertSet('step', 'code');
    }

    User::factory()->create(['email' => 'finns@example.test']);
    Livewire::test(LoginForm::class)->set('email', 'finns@example.test')->call('sendCode');

    expect(RateLimiter::attempts(SendLoginCode::GLOBAL_KEY))->toBe(0);

    // A code to an address without an account (open self-registration or an invitation) counts.
    app(SendLoginCode::class)('ny@example.test', '192.0.2.1');

    expect(RateLimiter::attempts(SendLoginCode::GLOBAL_KEY))->toBe(1);
});

it('cannot be locked for existing accounts by the service-wide cap', function () {
    RateLimiter::increment(SendLoginCode::GLOBAL_KEY, 3600, SendLoginCode::MAX_SENDS_PER_HOUR_GLOBAL);

    User::factory()->create(['email' => 'finns@example.test']);
    Livewire::test(LoginForm::class)->set('email', 'finns@example.test')->call('sendCode')->assertHasNoErrors();

    Mail::assertSent(LoginCodeMail::class, fn (LoginCodeMail $mail): bool => $mail->hasTo('finns@example.test'));
});

it('sends nothing to new addresses once the service-wide cap is reached, and warns once per ten minutes', function () {
    RateLimiter::increment(SendLoginCode::GLOBAL_KEY, 3600, SendLoginCode::MAX_SENDS_PER_HOUR_GLOBAL);
    Log::spy();

    app(SendLoginCode::class)('ny1@example.test', '192.0.2.1');
    app(SendLoginCode::class)('ny2@example.test', '192.0.2.1');

    Mail::assertNothingOutgoing();
    Log::shouldHaveReceived('warning')->once();
});

it('buckets IPv6 addresses by /64 for the per-address limit', function () {
    expect(ClientAddressBucket::for('2001:db8:1:2::1'))->toBe('2001:db8:1:2::/64')
        ->and(ClientAddressBucket::for('2001:db8:1:2:ffff:ffff:ffff:ffff'))->toBe('2001:db8:1:2::/64')
        ->and(ClientAddressBucket::for('2001:db8:1:3::1'))->toBe('2001:db8:1:3::/64')
        ->and(ClientAddressBucket::for('203.0.113.7'))->toBe('203.0.113.7')
        // An IPv4-mapped IPv6 address is the IPv4 client it carries.
        ->and(ClientAddressBucket::for('::ffff:203.0.113.7'))->toBe('203.0.113.7')
        ->and(ClientAddressBucket::for('::FFFF:cb00:7107'))->toBe('203.0.113.7')
        ->and(ClientAddressBucket::for(null))->toBe('unknown');

    // The form counts requests under the bucket of the client address (Livewire's test requests come from 127.0.0.1).
    RateLimiter::increment('login-code:send:'.ClientAddressBucket::for('127.0.0.1'), 600, 10);

    Livewire::test(LoginForm::class)->set('email', 'okand@example.test')->call('sendCode')
        ->assertHasErrors(['email' => 'För många försök. Vänta en stund och försök igen.']);
});

it('never fills in codes on a server address, even with the local settings', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['auth.login_codes.autofill' => true, 'app.url' => 'https://nonox.app']);

    expect(LoginCodeAutofill::enabled())->toBeFalse();

    config(['app.url' => 'http://oss.test']);

    expect(LoginCodeAutofill::enabled())->toBeTrue();
});
