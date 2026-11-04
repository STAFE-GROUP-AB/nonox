<?php

declare(strict_types=1);

use App\Actions\Auth\Passkeys\PasskeyCeremony;
use App\Actions\Auth\Passkeys\RegisterPasskey;
use App\Actions\Auth\Passkeys\ResetPasskeys;
use App\Domain\Auth\PasskeyRequirement;
use App\Http\Middleware\RequirePasskey;
use App\Livewire\Auth\LoginForm;
use App\Livewire\Auth\PasskeyChallenge;
use App\Livewire\Company\MemberManager;
use App\Livewire\Company\PasskeyPolicy;
use App\Livewire\Profile\Passkeys;
use App\Mail\LoginCodeMail;
use App\Mail\PasskeyAddedMail;
use App\Mail\PasskeysResetMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use App\Support\Auth\PasskeySession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Jetstream;
use Laravel\Passkeys\Passkey;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;
use Tests\Support\StaffLedger;
use Tests\Support\VirtualAuthenticator;

beforeEach(function () {
    Mail::fake();
    config([
        'passkeys.relying_party_id' => 'nonox.test',
        'passkeys.allowed_origins' => ['https://nonox.test'],
    ]);
});

/** Registers a passkey for the user through the real ceremony and returns the authenticator holding it. */
function givePasskey(User $user, string $name = 'MacBook'): VirtualAuthenticator
{
    $authenticator = new VirtualAuthenticator;

    app(RegisterPasskey::class)($user, $name, $authenticator->register(PasskeyCeremony::startRegistration($user)));

    return $authenticator;
}

/** Runs the email code step of the sign-in form and returns the component. */
function codeStep(string $email): mixed
{
    $form = Livewire::test(LoginForm::class)->set('email', $email)->call('sendCode');

    $code = null;
    Mail::assertQueued(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code, $email) {
        if ($mail->hasTo($email)) {
            $code = $mail->code;
        }

        return true;
    });

    return $form->set('code', (string) $code)->call('verify');
}

/** @return array<array-key, mixed> what a Livewire method returned */
function returned(mixed $component): array
{
    $value = [];
    $component->assertReturned(function (mixed $data) use (&$value) {
        $value = $data;

        return true;
    });

    return (array) $value;
}

// --- Who must use a passkey -------------------------------------------------

it('names exactly the permissions that reveal health data or full personal numbers', function () {
    expect(PasskeyRequirement::SENSITIVE_PERMISSIONS)->toBe([
        'payroll:view-sensitive',
        'payroll:view',
        'payroll:archive-view',
        'staff-ledger:view-sensitive',
        'staff-ledger:control-visit',
    ]);

    foreach (PasskeyRequirement::SENSITIVE_PERMISSIONS as $permission) {
        expect(Jetstream::$permissions)->toContain($permission);
    }
});

it('requires a passkey of those who can see payroll data, and not of a bookkeeper', function () {
    [$owner, $company] = Bookkeeping::company();
    $bookkeeper = Bookkeeping::member($company, 'bookkeeper');
    $accountant = Bookkeeping::member($company, 'accountant');
    $approver = Bookkeeping::member($company, 'approver');

    // Before payroll has ever been turned on there is nothing sensitive to see.
    expect(PasskeyRequirement::appliesTo($owner))->toBeFalse()
        ->and(PasskeyRequirement::appliesTo($accountant))->toBeFalse();

    Payroll::setUp($company, $owner);
    $payrollAdmin = Bookkeeping::member($company, 'payroll_admin');

    expect(PasskeyRequirement::appliesTo($owner->fresh()))->toBeTrue()
        ->and(PasskeyRequirement::appliesTo($accountant->fresh()))->toBeTrue()
        ->and(PasskeyRequirement::appliesTo($payrollAdmin->fresh()))->toBeTrue()
        ->and(PasskeyRequirement::appliesTo($bookkeeper->fresh()))->toBeFalse()
        ->and(PasskeyRequirement::appliesTo($approver->fresh()))->toBeFalse();

    // Turning payroll off keeps the archive readable, so the requirement stays.
    app(ModuleManager::class)->disable(PayrollModule::KEY, $company, $owner, confirmed: true);

    expect(PasskeyRequirement::appliesTo($owner->fresh()))->toBeTrue();
});

it('requires a passkey of those who see full personal numbers in the staff ledger', function () {
    [$owner, $company] = Bookkeeping::company();
    StaffLedger::enable($company, $owner);
    $manager = Bookkeeping::member($company, 'staff_ledger_manager');
    $auditor = Bookkeeping::member($company, 'auditor');
    $staff = Bookkeeping::member($company, 'staff_ledger_staff');

    expect(PasskeyRequirement::appliesTo($manager))->toBeTrue()
        ->and(PasskeyRequirement::appliesTo($owner->fresh()))->toBeTrue()
        ->and(PasskeyRequirement::appliesTo($auditor))->toBeFalse()
        ->and(PasskeyRequirement::appliesTo($staff))->toBeFalse();
});

it('counts a sensitive role in any of the user\'s companies', function () {
    [, $plain] = Bookkeeping::company();
    [$payrollOwner, $payrollCompany] = Bookkeeping::company();
    Payroll::setUp($payrollCompany, $payrollOwner);

    $user = Bookkeeping::member($plain, 'bookkeeper');
    expect(PasskeyRequirement::appliesTo($user))->toBeFalse();

    $payrollCompany->users()->attach($user, ['role' => 'payroll_admin']);

    expect(PasskeyRequirement::appliesTo($user->fresh()))->toBeTrue();
});

it('requires a passkey of everyone in a company whose owner asks for it', function () {
    [$owner, $company] = Bookkeeping::company();
    $bookkeeper = Bookkeeping::member($company, 'bookkeeper');

    Livewire::actingAs($owner)->test(PasskeyPolicy::class, ['company' => $company])
        ->set('required', true)->call('save')
        ->assertDispatched('toast');

    expect($company->fresh()->require_passkeys)->toBeTrue()
        ->and(PasskeyRequirement::appliesTo($bookkeeper->fresh()))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'company.passkeys_required_changed')->where('company_id', $company->id)->exists())->toBeTrue();
});

it('lets only the owner change the company passkey setting', function () {
    [, $company] = Bookkeeping::company();
    $admin = Bookkeeping::member($company, 'admin');

    Livewire::actingAs($admin)->test(PasskeyPolicy::class, ['company' => $company])->assertForbidden();

    expect($company->fresh()->require_passkeys)->toBeFalse();
});

// --- Registration ---------------------------------------------------------

it('adds a passkey from the profile, keeps only the public key, audits without key material and emails the user', function () {
    [$owner] = Bookkeeping::company();
    $authenticator = new VirtualAuthenticator;

    $component = Livewire::actingAs($owner)->test(Passkeys::class)->set('name', 'MacBook')->call('registrationOptions');
    $options = returned($component);

    expect($options['authenticatorSelection']['userVerification'])->toBe('required')
        ->and($options['attestation'] ?? 'none')->toBe('none')
        ->and($options['rp']['id'])->toBe('nonox.test');

    $component->call('register', $authenticator->register($options))->assertHasNoErrors()->assertDispatched('toast');

    $passkey = Passkey::query()->where('user_id', $owner->id)->sole();
    $stored = json_encode($passkey->credential);

    expect($passkey->name)->toBe('MacBook')
        ->and($passkey->credential_id)->toBe(VirtualAuthenticator::b64url($authenticator->credentialId))
        ->and($stored)->not->toContain('PRIVATE')
        ->and(PasskeySession::isVerified())->toBeTrue();

    $log = AuditLog::query()->where('action', 'passkey.added')->where('user_id', $owner->id)->sole();
    expect(array_keys($log->payload))->toBe(['name', 'authenticator'])
        ->and(json_encode($log->payload))->not->toContain($passkey->credential_id);

    Mail::assertSent(PasskeyAddedMail::class, fn (PasskeyAddedMail $mail) => $mail->hasTo($owner->email) && $mail->passkeyName === 'MacBook');
});

it('refuses a passkey made without user verification', function () {
    [$owner] = Bookkeeping::company();
    $authenticator = new VirtualAuthenticator(verifiesUser: false);

    expect(fn () => app(RegisterPasskey::class)($owner, 'Nyckel', $authenticator->register(PasskeyCeremony::startRegistration($owner))))
        ->toThrow(ValidationException::class);

    expect($owner->passkeys()->count())->toBe(0);
});

it('refuses a passkey answered from another origin, or to a challenge used already', function () {
    [$owner] = Bookkeeping::company();

    $foreign = new VirtualAuthenticator(origin: 'https://evil.example');
    expect(fn () => app(RegisterPasskey::class)($owner, 'Falsk', $foreign->register(PasskeyCeremony::startRegistration($owner))))
        ->toThrow(ValidationException::class);

    $authenticator = new VirtualAuthenticator;
    $answer = $authenticator->register(PasskeyCeremony::startRegistration($owner));
    app(RegisterPasskey::class)($owner, 'MacBook', $answer);

    expect(fn () => app(RegisterPasskey::class)($owner, 'Igen', $answer))->toThrow(ValidationException::class);
    expect($owner->passkeys()->count())->toBe(1);
});

it('does not let a challenge made for one user be answered for another', function () {
    [$owner] = Bookkeeping::company();
    $other = User::factory()->create();
    $authenticator = new VirtualAuthenticator;

    $answer = $authenticator->register(PasskeyCeremony::startRegistration($owner));

    expect(fn () => app(RegisterPasskey::class)($other, 'Stulen', $answer))->toThrow(ValidationException::class);
    expect(Passkey::query()->count())->toBe(0);
});

// --- Sign-in --------------------------------------------------------------

it('asks a user with a passkey for it after the code, and signs in only when it matches', function () {
    [$owner] = Bookkeeping::company();
    $authenticator = givePasskey($owner);

    $form = codeStep($owner->email)->assertSet('step', 'passkey');
    expect(Auth::check())->toBeFalse();

    $options = returned($form->call('passkeyAssertionOptions'));
    expect($options['userVerification'])->toBe('required')
        ->and($options['allowCredentials'][0]['id'])->toBe(VirtualAuthenticator::b64url($authenticator->credentialId));

    $form->call('confirmPasskey', $authenticator->authenticate($options, $owner->getPasskeyUserHandle()))
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect(Auth::id())->toBe($owner->id)
        ->and(PasskeySession::isVerified())->toBeTrue()
        ->and($owner->passkeys()->sole()->last_used_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'passkey.verified')->where('user_id', $owner->id)->exists())->toBeTrue();
});

it('does not sign in with someone else\'s passkey or one that skipped user verification', function () {
    [$owner] = Bookkeeping::company();
    givePasskey($owner);
    $intruder = User::factory()->create();
    $intrudersKey = givePasskey($intruder, 'Inkräktare');

    $form = codeStep($owner->email)->assertSet('step', 'passkey');
    $options = returned($form->call('passkeyAssertionOptions'));
    $form->call('confirmPasskey', $intrudersKey->authenticate($options))->assertHasErrors('passkey');

    expect(Auth::check())->toBeFalse();

    $unverified = givePasskey($owner, 'Utan PIN');
    $unverified->verifiesUser = false;
    $options = returned($form->call('passkeyAssertionOptions'));
    $form->call('confirmPasskey', $unverified->authenticate($options))->assertHasErrors('passkey');

    expect(Auth::check())->toBeFalse();
});

it('makes a user who must have a passkey add one after the code before signing in', function () {
    [$owner, $company] = Bookkeeping::company();
    Payroll::setUp($company, $owner);

    $form = codeStep($owner->email)->assertSet('step', 'passkey-register')->assertSee('sjukfrånvaro');
    expect(Auth::check())->toBeFalse();

    $options = returned($form->set('passkeyName', 'iPhone')->call('passkeyRegistrationOptions'));
    $form->call('registerFirstPasskey', (new VirtualAuthenticator)->register($options))
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect(Auth::id())->toBe($owner->id)
        ->and($owner->passkeys()->sole()->name)->toBe('iPhone')
        ->and(PasskeySession::isVerified())->toBeTrue();
});

it('never lets the sign-in form add a passkey for a user who already has one', function () {
    [$owner] = Bookkeeping::company();
    givePasskey($owner);

    $form = codeStep($owner->email)->assertSet('step', 'passkey');

    $form->set('passkeyName', 'Inkräktare')->call('passkeyRegistrationOptions')->assertForbidden();
    expect($owner->passkeys()->count())->toBe(1);
});

it('signs in a user who needs no passkey with the code alone, as before', function () {
    [, $company] = Bookkeeping::company();
    $bookkeeper = Bookkeeping::member($company, 'bookkeeper');

    codeStep($bookkeeper->email)->assertRedirect(route('dashboard'));

    expect(Auth::id())->toBe($bookkeeper->id);
});

it('forgets the passkey step when the user starts over', function () {
    [$owner] = Bookkeeping::company();
    givePasskey($owner);

    $form = codeStep($owner->email)->assertSet('step', 'passkey')->call('startOver')->assertSet('step', 'email');

    $form->call('passkeyAssertionOptions')->assertHasErrors('passkey');
});

// --- A signed-in session that has to pass the step -------------------------

it('sends a session without the passkey step to the passkey page, and lets it through after', function () {
    [$owner, $company] = Bookkeeping::company();
    Payroll::setUp($company, $owner);

    $this->be($owner);
    $this->get(route('dashboard'))->assertRedirect(route('passkeys.challenge'));
    $this->put(route('current-team.update'), ['team_id' => $company->id])->assertForbidden();
    $this->get(route('passkeys.challenge'))->assertOk()->assertSee('Lägg till en passkey');
    $this->get(route('start'))->assertOk();

    session()->put(PasskeySession::VERIFIED, time());
    $this->get(route('dashboard'))->assertOk();
});

it('lets only the passkey page\'s own Livewire updates through before the step', function () {
    [$owner, $company] = Bookkeeping::company();
    Payroll::setUp($company, $owner);
    $this->be($owner);
    session()->forget(PasskeySession::VERIFIED);

    $update = function (string $component) use ($owner): int {
        $request = Request::create('/livewire/update', 'POST', ['components' => [['snapshot' => json_encode(['data' => [], 'memo' => ['id' => 'x', 'name' => $component], 'checksum' => 'x']), 'updates' => [], 'calls' => []]]]);
        $request->headers->set('X-Livewire', '1');
        $request->setUserResolver(fn () => $owner);

        try {
            return (new RequirePasskey)->handle($request, fn () => response('ok'))->getStatusCode();
        } catch (HttpException $exception) {
            return $exception->getStatusCode();
        }
    };

    expect($update('auth.passkey-challenge'))->toBe(200)
        ->and($update('accounting.voucher-list'))->toBe(403)
        ->and($update('profile.passkeys'))->toBe(403);
});

it('lets the passkey page pass the step for a signed-in session', function () {
    [$owner] = Bookkeeping::company();
    $authenticator = givePasskey($owner);

    $this->be($owner);
    session()->forget(PasskeySession::VERIFIED);

    $page = Livewire::test(PasskeyChallenge::class);
    $options = returned($page->call('passkeyAssertionOptions'));
    $page->call('confirmPasskey', $authenticator->authenticate($options))->assertRedirect(route('dashboard'));

    expect(PasskeySession::isVerified())->toBeTrue();
});

// --- Removing --------------------------------------------------------------

it('refuses to remove the last passkey while one is required, and removes one otherwise', function () {
    [$owner, $company] = Bookkeeping::company();
    Payroll::setUp($company, $owner);
    givePasskey($owner, 'MacBook');

    $profile = Livewire::actingAs($owner)->test(Passkeys::class);
    $first = $owner->passkeys()->sole();

    $profile->call('remove', $first->id)->assertHasErrors('passkey');
    expect($owner->passkeys()->count())->toBe(1);

    givePasskey($owner, 'iPhone');
    $profile->call('remove', $first->id)->assertHasNoErrors()->assertDispatched('toast');

    expect($owner->passkeys()->pluck('name')->all())->toBe(['iPhone'])
        ->and(AuditLog::query()->where('action', 'passkey.removed')->where('user_id', $owner->id)->exists())->toBeTrue();
});

it('lets a user who needs no passkey remove their only one, but never someone else\'s', function () {
    [, $company] = Bookkeeping::company();
    $bookkeeper = Bookkeeping::member($company, 'bookkeeper');
    $other = User::factory()->create();
    givePasskey($other);
    givePasskey($bookkeeper);

    Livewire::actingAs($bookkeeper)->test(Passkeys::class)->call('remove', $other->passkeys()->sole()->id)->assertNotFound();
    Livewire::actingAs($bookkeeper)->test(Passkeys::class)->call('remove', $bookkeeper->passkeys()->sole()->id)->assertHasNoErrors();

    expect($bookkeeper->passkeys()->count())->toBe(0)
        ->and($other->passkeys()->count())->toBe(1);
});

// --- Recovery ----------------------------------------------------------------

it('lets an administrator who signed in with a passkey reset a member\'s passkeys, audited in the company, with an email', function () {
    [$owner, $company] = Bookkeeping::company();
    $admin = Bookkeeping::member($company, 'admin');
    $member = Bookkeeping::member($company, 'accountant');
    $bystander = User::factory()->create();
    givePasskey($member);
    givePasskey($member, 'iPhone');
    givePasskey($bystander);

    $this->be($admin);
    session()->put(PasskeySession::VERIFIED, time());

    Livewire::test(MemberManager::class, ['team' => $company])
        ->assertSee('Återställ passkeys')
        ->call('resetPasskeys', $member->id)
        ->assertDispatched('toast');

    expect($member->passkeys()->count())->toBe(0)
        ->and($member->fresh()->sessions_valid_from)->not->toBeNull()
        ->and($bystander->passkeys()->count())->toBe(1);

    $log = AuditLog::query()->where('action', 'passkey.reset')->sole();
    expect($log->company_id)->toBe($company->id)
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->subject_id)->toBe((string) $member->id)
        ->and($log->payload['removed'])->toBe(2);

    Mail::assertSent(PasskeysResetMail::class, fn (PasskeysResetMail $mail) => $mail->hasTo($member->email) && $mail->companyName === $company->name && $mail->resetBy === $admin->name);
});

it('refuses a reset from someone who has not signed in with a passkey, cannot manage members, or shares no company', function () {
    [$owner, $company] = Bookkeeping::company();
    [$otherOwner, $otherCompany] = Bookkeeping::company();
    $admin = Bookkeeping::member($company, 'admin');
    $bookkeeper = Bookkeeping::member($company, 'bookkeeper');
    $member = Bookkeeping::member($company, 'accountant');
    $otherAdmin = Bookkeeping::member($otherCompany, 'admin');
    givePasskey($member);
    $reset = app(ResetPasskeys::class);

    expect(fn () => $reset($admin, $company, $member, false))->toThrow(AuthorizationException::class)
        ->and(fn () => $reset($bookkeeper, $company, $member, true))->toThrow(AuthorizationException::class)
        // An administrator of another company cannot reach this member, whichever company is named.
        ->and(fn () => $reset($otherAdmin, $otherCompany, $member, true))->toThrow(AuthorizationException::class)
        ->and(fn () => $reset($otherAdmin, $company, $member, true))->toThrow(AuthorizationException::class)
        ->and(fn () => $reset($otherOwner, $company, $member, true))->toThrow(AuthorizationException::class)
        ->and(fn () => $reset($member, $company, $member, true))->toThrow(AuthorizationException::class);

    expect($member->passkeys()->count())->toBe(1);
    Mail::assertNotSent(PasskeysResetMail::class);
});

it('does not let an administrator reset the owner\'s passkeys, but lets the owner reset an administrator\'s', function () {
    [$owner, $company] = Bookkeeping::company();
    $admin = Bookkeeping::member($company, 'admin');
    givePasskey($owner);
    givePasskey($admin);

    expect(ResetPasskeys::allowed($admin, $company, $owner, true))->toBeFalse();

    app(ResetPasskeys::class)($owner, $company, $admin, true);

    expect($admin->passkeys()->count())->toBe(0)
        ->and($owner->passkeys()->count())->toBe(1);
});

it('lets the one running the server reset a user\'s passkeys from the command line', function () {
    [$owner, $company] = Bookkeeping::company();
    givePasskey($owner);

    $this->artisan('passkeys:reset', ['email' => strtoupper($owner->email), '--force' => true])->assertSuccessful();

    expect($owner->passkeys()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'passkey.reset')->where('company_id', $company->id)->exists())->toBeTrue();
    Mail::assertSent(PasskeysResetMail::class, fn (PasskeysResetMail $mail) => $mail->hasTo($owner->email) && $mail->companyName === null);
});

// --- Local development -------------------------------------------------------

it('skips the passkey step only locally, on a local address, with the flag on', function (string $environment, string $url, bool $flag, bool $skipped) {
    app()->detectEnvironment(fn () => $environment);
    config(['app.url' => $url, 'auth.passkeys.skip_locally' => $flag]);

    expect(PasskeySession::skippedLocally())->toBe($skipped);
})->with([
    'local, localhost, on' => ['local', 'http://localhost:8000', true, true],
    'local, *.test, on' => ['local', 'http://oss.test', true, true],
    'local, flag off' => ['local', 'http://oss.test', false, false],
    'local, real host' => ['local', 'https://nonox.app', true, false],
    'production, localhost' => ['production', 'http://localhost', true, false],
    'production, real host' => ['production', 'https://nonox.app', true, false],
    'staging' => ['staging', 'http://oss.test', true, false],
    'testing' => ['testing', 'http://localhost', true, false],
]);

it('asks for the passkey in production even with the local flag on', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['app.url' => 'http://localhost', 'auth.passkeys.skip_locally' => true]);
    [$owner] = Bookkeeping::company();
    givePasskey($owner);

    codeStep($owner->email)->assertSet('step', 'passkey');
    expect(Auth::check())->toBeFalse();

    $this->be($owner);
    session()->forget(PasskeySession::VERIFIED);
    $this->get(route('dashboard'))->assertRedirect(route('passkeys.challenge'));
});

it('signs in without the passkey step locally when the flag is on', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['app.url' => 'http://oss.test', 'auth.passkeys.skip_locally' => true]);
    [$owner] = Bookkeeping::company();
    givePasskey($owner);

    codeStep($owner->email)->assertRedirect(route('dashboard'));
    expect(Auth::id())->toBe($owner->id);
});

it('keeps passkeys per user, so a company is not a way to see anyone\'s passkeys', function () {
    [$ownerA] = Bookkeeping::company();
    [$ownerB] = Bookkeeping::company();
    givePasskey($ownerA, 'A');
    givePasskey($ownerB, 'B');

    Livewire::actingAs($ownerA)->test(Passkeys::class)->assertSee('A')->assertDontSee('>B<', false);

    expect(Company::query()->count())->toBeGreaterThanOrEqual(2)
        ->and($ownerA->passkeys()->pluck('name')->all())->toBe(['A']);
});
