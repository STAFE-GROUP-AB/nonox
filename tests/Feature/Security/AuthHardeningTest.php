<?php

declare(strict_types=1);

use App\Livewire\Auth\LoginForm;
use App\Livewire\Company\MemberManager;
use App\Mail\LoginCodeMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Contracts\AddsTeamMembers;
use Livewire\Livewire;

/*
 * Findings 1–5 of the security review of 2026-11-01.
 */

beforeEach(function () {
    Mail::fake();
});

/** @return array{0: User, 1: Company, 2: User} the owner, the company and an admin */
function companyWithAdmin(): array
{
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentTeam;
    $admin = User::factory()->create();
    $company->users()->attach($admin, ['role' => 'admin']);

    return [$owner, $company, $admin];
}

function inviteAs(User $user, Company $company, string $email, string $role = 'admin'): void
{
    test()->actingAs($user);

    Livewire::test(MemberManager::class, ['team' => $company])
        ->set('addTeamMemberForm', ['email' => $email, 'role' => $role])
        ->call('addTeamMember')
        ->assertHasNoErrors();
}

// 1. Invitations are withdrawn with their sender.

it('records who sent an invitation', function () {
    [, $company, $admin] = companyWithAdmin();

    inviteAs($admin, $company, 'alt@example.com');

    expect(TeamInvitation::where('team_id', $company->id)->sole()->invited_by)->toBe($admin->id);
});

it('withdraws the pending invitations of an admin who is removed, with an audit entry and a toast', function () {
    [$owner, $company, $admin] = companyWithAdmin();

    inviteAs($admin, $company, 'alt@example.com');
    inviteAs($admin, $company, 'alt2@example.com', 'bookkeeper');
    inviteAs($owner, $company, 'colleague@example.com');

    $this->actingAs($owner);

    Livewire::test(MemberManager::class, ['team' => $company])
        ->set('teamMemberIdBeingRemoved', $admin->id)
        ->call('removeTeamMember')
        ->assertDispatched('toast', message: "{$admin->name} har inte längre tillgång till företaget. 2 obesvarade inbjudningar som {$admin->name} skickat är återkallade.");

    expect(TeamInvitation::where('team_id', $company->id)->pluck('email')->all())->toBe(['colleague@example.com'])
        ->and(AuditLog::where('company_id', $company->id)->where('action', 'company.invitation_withdrawn')->count())->toBe(2);

    $entry = AuditLog::where('company_id', $company->id)->where('action', 'company.invitation_withdrawn')->firstOrFail();
    expect($entry->payload['reason'])->toBe('member_removed')
        ->and($entry->payload['invited_by'])->toBe($admin->id);
});

it('withdraws the invitations of someone who leaves the company', function () {
    [, $company, $admin] = companyWithAdmin();

    inviteAs($admin, $company, 'alt@example.com');

    $this->actingAs($admin);

    Livewire::test(MemberManager::class, ['team' => $company])->call('leaveTeam');

    expect(TeamInvitation::where('team_id', $company->id)->exists())->toBeFalse();
});

it('withdraws the invitations of an admin whose new role cannot manage members', function () {
    [$owner, $company, $admin] = companyWithAdmin();

    inviteAs($admin, $company, 'alt@example.com');

    $this->actingAs($owner);

    Livewire::test(MemberManager::class, ['team' => $company])
        ->call('manageRole', $admin->id)
        ->set('currentRole', 'bookkeeper')
        ->call('updateRole')
        ->assertDispatched('toast', message: "{$admin->name} är nu bokförare. En obesvarad inbjudan som {$admin->name} skickat är återkallad.");

    expect(TeamInvitation::where('team_id', $company->id)->exists())->toBeFalse();

    $entry = AuditLog::where('company_id', $company->id)->where('action', 'company.invitation_withdrawn')->sole();
    expect($entry->payload['reason'])->toBe('role_changed');
});

it('keeps the invitations of someone who can still manage members, and those of others', function () {
    [$owner, $company, $admin] = companyWithAdmin();
    $other = User::factory()->create();
    $company->users()->attach($other, ['role' => 'admin']);

    inviteAs($admin, $company, 'alt@example.com');
    inviteAs($other, $company, 'friend@example.com');

    $this->actingAs($owner);

    Livewire::test(MemberManager::class, ['team' => $company])
        ->call('manageRole', $other->id)
        ->set('currentRole', 'accountant')
        ->call('updateRole')
        ->assertDispatched('toast', message: "{$other->name} är nu redovisningskonsult. En obesvarad inbjudan som {$other->name} skickat är återkallad.");

    expect(TeamInvitation::where('team_id', $company->id)->pluck('email')->all())->toBe(['alt@example.com']);

    // A role change that keeps members:manage withdraws nothing.
    $company->users()->updateExistingPivot($other->id, ['role' => 'admin']);

    inviteAs($admin, $company, 'alt3@example.com');
    $this->actingAs($owner);

    Livewire::test(MemberManager::class, ['team' => $company->fresh()])
        ->call('manageRole', $admin->id)
        ->set('currentRole', 'admin')
        ->call('updateRole')
        ->assertDispatched('toast', message: "{$admin->name} är nu administratör.");

    expect(TeamInvitation::where('team_id', $company->id)->count())->toBe(2);
});

it('lets a removed admin no longer come back through an invitation they sent', function () {
    [$owner, $company, $admin] = companyWithAdmin();

    inviteAs($admin, $company, 'alt@example.com');
    $invitation = TeamInvitation::where('team_id', $company->id)->sole();

    $this->actingAs($owner);
    Livewire::test(MemberManager::class, ['team' => $company])
        ->set('teamMemberIdBeingRemoved', $admin->id)
        ->call('removeTeamMember');

    $alt = User::factory()->create(['email' => 'alt@example.com']);

    $this->actingAs($alt)
        ->post(route('team-invitations.accept-from-onboarding', $invitation->id))
        ->assertNotFound();

    expect($alt->fresh()->belongsToTeam($company))->toBeFalse();
});

// 2. Email addresses regardless of case.

it('stores email addresses in lowercase', function () {
    $user = User::factory()->create(['email' => '  Anna@Firma.SE ']);

    expect($user->email)->toBe('anna@firma.se');

    [, $company, $admin] = companyWithAdmin();
    inviteAs($admin, $company, 'Bertil@Firma.se');

    expect(TeamInvitation::where('team_id', $company->id)->sole()->email)->toBe('bertil@firma.se');
});

it('lets someone accept an invitation sent to their address in another case', function () {
    [, $company, $admin] = companyWithAdmin();

    // An invitation stored before addresses were lowercased.
    $invitationId = DB::table('team_invitations')->insertGetId([
        'team_id' => $company->id, 'email' => 'Anna@Firma.se', 'role' => 'bookkeeper', 'invited_by' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $anna = User::factory()->create(['email' => 'anna@firma.se']);

    $this->actingAs($anna)
        ->post(route('team-invitations.accept-from-onboarding', $invitationId))
        ->assertRedirect(route('dashboard'));

    expect($anna->fresh()->belongsToTeam($company))->toBeTrue();
});

it('adds a member whose address is typed in another case, and sees that they already belong', function () {
    [$owner, $company] = companyWithAdmin();
    $anna = User::factory()->create(['email' => 'anna@firma.se']);

    app(AddsTeamMembers::class)->add($owner, $company, 'ANNA@Firma.se', 'bookkeeper');

    expect($anna->fresh()->belongsToTeam($company))->toBeTrue()
        ->and($company->fresh()->hasUserWithEmail('Anna@FIRMA.se'))->toBeTrue();

    $this->actingAs($owner);

    Livewire::test(MemberManager::class, ['team' => $company->fresh()])
        ->set('addTeamMemberForm', ['email' => 'Anna@Firma.se', 'role' => 'admin'])
        ->call('addTeamMember')
        ->assertHasErrors('email');
});

it('signs in a user whose stored address has capitals', function () {
    // A row from before addresses were lowercased.
    $id = DB::table('users')->insertGetId([
        'name' => 'Cecilia', 'email' => 'Cecilia@Firma.se', 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $component = Livewire::test(LoginForm::class)->set('email', 'Cecilia@Firma.se')->call('sendCode');

    $code = null;
    Mail::assertSent(LoginCodeMail::class, function ($mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    $component->set('code', (string) $code)->call('verify')->assertRedirect(route('dashboard'));

    expect(Auth::id())->toBe($id)
        ->and(DB::table('users')->whereRaw('lower(email) = ?', ['cecilia@firma.se'])->count())->toBe(1);
});

// 3. Bearer tokens never open web pages.

it('does not accept an API token on web pages', function () {
    $user = User::factory()->withCompany()->create();
    $token = $user->createToken('test')->plainTextToken;

    expect($token)->toContain('|nonox_');

    // Pages on the session guard, and module pages still on auth:sanctum.
    foreach ([route('dashboard'), '/flytt/fortnox', '/inkorg', '/personalliggare'] as $page) {
        $this->withHeader('Authorization', "Bearer {$token}")
            ->get($page)
            ->assertRedirect(route('login'));
    }
});

it('uses the session guard for pages and lets API tokens expire', function () {
    expect(config('jetstream.guard'))->toBe('web')
        ->and(config('sanctum.expiration'))->toBeInt()->toBeGreaterThan(0)
        ->and(config('sanctum.token_prefix'))->not->toBe('');

    $pageRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->reject(fn ($route) => str_starts_with($route->uri(), 'api/'))
        ->map(fn ($route) => $route->uri())
        ->values();

    // Inbox and staff ledger routes are switched in their own modules; until then
    // Sanctum ignores bearer tokens outside /api (AppServiceProvider), tested above.
    expect($pageRoutes->reject(fn (string $uri) => str_starts_with($uri, 'inkorg') || str_starts_with($uri, 'personalliggare'))->all())->toBe([]);
});

it('still signs people in and out with the session', function () {
    $user = User::factory()->withCompany()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

// 4. The example environment is safe for a server.

it('ships an example environment that is safe on a server', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/^APP_ENV=production$/m')
        ->toMatch('/^APP_DEBUG=false$/m')
        ->toMatch('/^OTP_AUTOFILL=false$/m')
        ->toMatch('/^SESSION_SECURE_COOKIE=true$/m')
        ->toMatch('/^# TRUSTED_PROXIES=$/m');
});

it('sends the session cookie over https only on a production server when SESSION_SECURE_COOKIE is not set', function () {
    $read = function (array $values): mixed {
        $saved = [];

        foreach ($values as $key => $value) {
            $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);

            if ($value !== null) {
                $_SERVER[$key] = $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        try {
            return (require base_path('config/session.php'))['secure'];
        } finally {
            foreach ($saved as $key => [$server, $env, $put]) {
                unset($_SERVER[$key], $_ENV[$key]);
                putenv($key);

                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }

                if ($env !== null) {
                    $_ENV[$key] = $env;
                }

                if ($put !== false) {
                    putenv("{$key}={$put}");
                }
            }
        }
    };

    expect($read(['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => null]))->toBeTrue()
        ->and($read(['APP_ENV' => 'local', 'SESSION_SECURE_COOKIE' => null]))->toBeFalse()
        ->and($read(['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => 'false']))->toBeFalse();
});

// 5. Trusted proxies are opt-in.

function clientIpFrom(string $remoteAddress, string $forwardedFor): string
{
    Route::get('/_test/client-ip', fn (Request $request) => (string) $request->ip());

    return (string) test()->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->withHeader('X-Forwarded-For', $forwardedFor)
        ->get('/_test/client-ip')
        ->getContent();
}

it('ignores X-Forwarded-For unless proxies are trusted', function () {
    config(['trustedproxy.proxies' => null]);

    expect(clientIpFrom('10.1.2.3', '203.0.113.5'))->toBe('10.1.2.3');
});

it('believes a trusted proxy about the client address', function () {
    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);

    expect(clientIpFrom('10.1.2.3', '203.0.113.5'))->toBe('203.0.113.5')
        ->and(clientIpFrom('198.51.100.7', '203.0.113.5'))->toBe('198.51.100.7');
});

it('reads trusted proxies from TRUSTED_PROXIES', function () {
    $read = function (?string $value): mixed {
        $value === null ? putenv('TRUSTED_PROXIES') : putenv("TRUSTED_PROXIES={$value}");

        try {
            return (require config_path('trustedproxy.php'))['proxies'];
        } finally {
            putenv('TRUSTED_PROXIES');
        }
    };

    expect($read(null))->toBeNull()
        ->and($read(''))->toBeNull()
        ->and($read('173.245.48.0/20, 103.21.244.0/22'))->toBe(['173.245.48.0/20', '103.21.244.0/22'])
        ->and($read('*'))->toBe('*');
});
