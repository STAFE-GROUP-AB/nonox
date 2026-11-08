<?php

declare(strict_types=1);

use App\Actions\Auth\SendLoginCode;
use App\Actions\Auth\VerifyLoginCode;
use App\Models\AuditLog;
use App\Models\LoginCode;
use App\Modules\ModuleManager;
use App\Modules\Portal\Actions\ManagePortalContacts;
use App\Modules\Portal\Domain\ContactStatus;
use App\Modules\Portal\Jobs\SendPortalLoginCode;
use App\Modules\Portal\Mail\PortalMail;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\PortalModule;
use App\Modules\Portal\Support\PortalAccess;
use App\Modules\Portal\Support\PortalSession;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Bookkeeping;
use Tests\Support\Portal;

/*
 * Signing in to the customer portal (ADR-075 §2, §3, §8): its own guard,
 * cookie and login codes, no account enumeration, invitations that need a
 * code as well as the link, and the session limits.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    Portal::enable($this->company, $this->owner);
    $this->customer = Portal::customer($this->company, $this->owner);
    $this->key = Portal::key($this->company);
});

it('answers every address the same at once and leaves the decision to a queued job', function () {
    Queue::fake();
    $active = Portal::contact($this->company, $this->owner, $this->customer, 'aktiv@kontorshuset.test');
    $disabled = Portal::contact($this->company, $this->owner, $this->customer, 'av@kontorshuset.test', 'Bo Avstängd');
    CurrentCompany::run($this->company, fn () => app(ManagePortalContacts::class)->disable($this->owner, $this->company, $disabled));
    CurrentCompany::run($this->company, fn () => app(ManagePortalContacts::class)->invite($this->owner, $this->company, $this->customer, ['name' => 'Ina Inbjuden', 'email' => 'inbjuden@kontorshuset.test']));

    $answers = [];

    foreach (['okand@example.test', 'inbjuden@kontorshuset.test', 'av@kontorshuset.test', 'aktiv@kontorshuset.test'] as $email) {
        $response = $this->post('/kundportal', ['email' => $email]);
        $response->assertRedirect(route('portal.code'));
        $answers[] = [$response->status(), session('status')];
    }

    expect(array_unique(array_map('serialize', $answers)))->toHaveCount(1)
        ->and($answers[0][1])->toBe('Om adressen har tillgång till en kundportal har vi skickat en kod.');
    Queue::assertPushed(SendPortalLoginCode::class, 4);
    expect(LoginCode::query()->count())->toBe(0);
});

it('sends a portal code only to an address with an active contact, and none to the others', function () {
    Portal::contact($this->company, $this->owner, $this->customer, 'aktiv@kontorshuset.test');

    foreach (['okand@example.test', 'aktiv@kontorshuset.test'] as $email) {
        (new SendPortalLoginCode($email, '192.0.2.1'))->handle(app(SendLoginCode::class), app(PortalAccess::class));
    }

    Mail::assertSent(PortalMail::class, 1);
    Mail::assertSent(PortalMail::class, fn (PortalMail $mail): bool => $mail->hasTo('aktiv@kontorshuset.test') && $mail->kind === PortalMail::CODE);
    expect(LoginCode::query()->pluck('audience')->all())->toBe(['PORTAL']);
});

it('never accepts a web code in the portal or a portal code on the web', function () {
    LoginCode::create(['email' => 'anna@kontorshuset.test', 'audience' => LoginCode::WEB, 'code_hash' => LoginCode::hash('12345678'), 'expires_at' => now()->addMinutes(10)]);
    LoginCode::create(['email' => 'bo@kontorshuset.test', 'audience' => LoginCode::PORTAL, 'code_hash' => LoginCode::hash('87654321'), 'expires_at' => now()->addMinutes(10)]);
    $verify = app(VerifyLoginCode::class);

    expect($verify('anna@kontorshuset.test', '12345678', LoginCode::PORTAL))->toBeFalse()
        ->and($verify('bo@kontorshuset.test', '87654321'))->toBeFalse()
        ->and($verify('bo@kontorshuset.test', '87654321', LoginCode::PORTAL))->toBeTrue()
        ->and($verify('anna@kontorshuset.test', '12345678'))->toBeTrue();
});

it('counts the portal caps apart from the web sign-in', function () {
    $send = app(SendLoginCode::class);

    for ($i = 0; $i < SendLoginCode::MAX_SENDS_PER_TEN_MINUTES; $i++) {
        $this->travel(61)->seconds();
        $send('anna@kontorshuset.test', '192.0.2.1', null, LoginCode::PORTAL, fn (string $code) => new PortalMail(PortalMail::CODE, null, code: $code));
    }

    $this->travel(61)->seconds();
    $send('anna@kontorshuset.test', '192.0.2.1', null, LoginCode::PORTAL, fn (string $code) => new PortalMail(PortalMail::CODE, null, code: $code));
    expect(LoginCode::query()->where('audience', 'PORTAL')->count())->toBe(SendLoginCode::MAX_SENDS_PER_TEN_MINUTES);
});

it('signs in with the code, on its own cookie, and leaves the web session alone', function () {
    $contact = Portal::contact($this->company, $this->owner, $this->customer);
    Queue::fake();

    $this->post('/kundportal', ['email' => 'Anna@Kontorshuset.test']);
    (new SendPortalLoginCode('anna@kontorshuset.test', '192.0.2.1'))->handle(app(SendLoginCode::class), app(PortalAccess::class));
    $code = null;
    Mail::assertSent(PortalMail::class, function (PortalMail $mail) use (&$code): bool {
        $code = $mail->code;

        return true;
    });

    $this->post('/kundportal/kod', ['code' => '00000000'])->assertSessionHasErrors('code');
    $response = $this->post('/kundportal/kod', ['code' => $code]);
    $response->assertRedirect(route('portal.choose'));

    expect(collect($response->headers->getCookies())->map->getName()->all())->toContain('nonox_kundportal')
        ->and(collect($response->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === 'nonox_kundportal')->getPath())->toBe('/kundportal')
        ->and(Auth::guard('portal')->id())->toBe($contact->portal_identity_id)
        ->and(Auth::guard('web')->check())->toBeFalse();

    $this->get(route('portal.choose'))->assertRedirect(route('portal.home', ['portalKey' => $this->key]));
    $this->get(route('portal.home', ['portalKey' => $this->key]))->assertOk()->assertSee('Översikt')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

it('accepts an invitation only with the link and a code sent to the invited address', function () {
    Queue::fake();
    $sent = CurrentCompany::run($this->company, fn () => app(ManagePortalContacts::class)->invite($this->owner, $this->company, $this->customer, ['name' => 'Anna Andersson', 'email' => 'anna@kontorshuset.test']));
    $token = basename((string) parse_url($sent['url'], PHP_URL_PATH));

    Mail::assertQueued(PortalMail::class, fn (PortalMail $mail): bool => $mail->kind === PortalMail::INVITATION && $mail->hasTo('anna@kontorshuset.test'));
    expect(PortalInvitation::query()->where('token_sha256', $token)->exists())->toBeFalse()
        ->and(PortalInvitation::query()->where('token_sha256', hash('sha256', $token))->exists())->toBeTrue();

    $this->get("/kundportal/inbjudan/{$token}")->assertOk()->assertSee('an***@kontorshuset.test')->assertDontSee('anna@kontorshuset.test');
    expect(Auth::guard('portal')->check())->toBeFalse();

    $this->post("/kundportal/inbjudan/{$token}");
    Queue::assertPushed(SendPortalLoginCode::class, fn (SendPortalLoginCode $job): bool => $job->email === 'anna@kontorshuset.test' && $job->invitedCompanyId === $this->company->id);
    (new SendPortalLoginCode('anna@kontorshuset.test', null, null, $this->company->id))->handle(app(SendLoginCode::class), app(PortalAccess::class));
    $code = null;
    Mail::assertSent(PortalMail::class, function (PortalMail $mail) use (&$code): bool {
        $code = $mail->code;

        return $mail->kind === PortalMail::CODE;
    });

    $this->post("/kundportal/inbjudan/{$token}/kod", ['code' => $code])->assertRedirect(route('portal.home', ['portalKey' => $this->key]));
    CurrentCompany::set($this->company);

    expect(PortalContact::query()->findOrFail($sent['contact']->id)->status)->toBe(ContactStatus::Active)
        ->and(AuditLog::query()->where('action', 'customer_portal.contact_accepted')->exists())->toBeTrue();

    // Used once: the link answers as gone.
    $this->get("/kundportal/inbjudan/{$token}")->assertNotFound();
});

it('revokes the earlier link when inviting again, and refuses expired and unknown links', function () {
    $first = CurrentCompany::run($this->company, fn () => app(ManagePortalContacts::class)->invite($this->owner, $this->company, $this->customer, ['name' => 'Anna', 'email' => 'anna@kontorshuset.test']));
    $second = CurrentCompany::run($this->company, fn () => app(ManagePortalContacts::class)->invite($this->owner, $this->company, $this->customer, ['name' => 'Anna', 'email' => 'anna@kontorshuset.test']));

    $this->get(parse_url($first['url'], PHP_URL_PATH))->assertNotFound();
    $this->get(parse_url($second['url'], PHP_URL_PATH))->assertOk();
    $this->get('/kundportal/inbjudan/inte-en-riktig-lank')->assertNotFound();

    $this->travel(15)->days();
    $this->get(parse_url($second['url'], PHP_URL_PATH))->assertNotFound();
});

it('ends the session after 30 minutes idle, after 12 hours, and from sessions_valid_from', function () {
    $contact = Portal::contact($this->company, $this->owner, $this->customer);
    $home = route('portal.home', ['portalKey' => $this->key]);

    Portal::signIn($this, $contact);
    $this->get($home)->assertOk();
    $this->travel(31)->minutes();
    $this->get($home)->assertRedirect(route('portal.login'));

    Portal::signIn($this, $contact);
    $this->withSession([PortalSession::STARTED => now()->getTimestamp() - 13 * 3600]);
    $this->get($home)->assertRedirect(route('portal.login'));

    Portal::signIn($this, $contact);
    PortalIdentity::query()->whereKey($contact->portal_identity_id)->update(['sessions_valid_from' => now()->addMinute()]);
    $this->get($home)->assertRedirect(route('portal.login'));
});

it('closes the portal at once for a disabled contact and when the module is turned off', function () {
    $contact = Portal::contact($this->company, $this->owner, $this->customer);
    $home = route('portal.home', ['portalKey' => $this->key]);

    Portal::signIn($this, $contact);
    $this->get($home)->assertOk();
    CurrentCompany::run($this->company, fn () => app(ManagePortalContacts::class)->disable($this->owner, $this->company, $contact));
    $this->get($home)->assertNotFound();
    expect(Auth::guard('portal')->check())->toBeFalse();

    $other = Portal::contact($this->company, $this->owner, $this->customer, 'bo@kontorshuset.test', 'Bo');
    Portal::signIn($this, $other);
    $this->get($home)->assertOk();
    app(ModuleManager::class)->disable(PortalModule::KEY, $this->company, $this->owner, confirmed: true);
    $this->get($home)->assertNotFound();
});

it('logs out of the portal without touching the NONOX session, and the reverse', function () {
    $contact = Portal::contact($this->company, $this->owner, $this->customer);
    Portal::signIn($this, $contact);

    $this->post(route('portal.logout'))->assertRedirect(route('portal.login'));
    expect(Auth::guard('portal')->check())->toBeFalse();

    $this->actingAs($this->owner)->get('/oversikt')->assertOk();
    expect(Auth::guard('web')->id())->toBe($this->owner->id);
});

it('shows the brand of the portal address the visitor came from, and none on the plain page', function () {
    $this->company->forceFill(['brand_color' => '#123456', 'legal_name' => 'Konsultbolaget i Malmö AB'])->save();

    $this->get('/kundportal')->assertOk()->assertDontSee('Konsultbolaget i Malmö AB');
    $this->get(route('portal.home', ['portalKey' => $this->key]))->assertRedirect(route('portal.login'));
    $this->get('/kundportal')->assertOk()->assertSee('Konsultbolaget i Malmö AB')->assertSee('Kundportalen drivs med NONOX');
});
