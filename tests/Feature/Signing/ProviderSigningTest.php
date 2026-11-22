<?php

declare(strict_types=1);

use App\Actions\Hq\UpdateInstanceSettings;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\ManageSigningProviders;
use App\Actions\Signing\PruneSigningLinks;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Actions\Signing\SignWithProvider;
use App\Domain\Signing\Chain\ChainVerifier;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Domain\Signing\Providers\FakeSignatureProvider;
use App\Domain\Signing\Providers\ProviderFailure;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningStatus;
use App\Domain\Signing\Verification\SignerCheck;
use App\Domain\Signing\Verification\SignerChecks;
use App\Livewire\Company\SigningProviderSettings;
use App\Livewire\Signing\SigningPanel;
use App\Models\AuditLog;
use App\Models\InstanceSetting;
use App\Models\SalesDocument;
use App\Models\SigningDocumentVersion;
use App\Models\SigningEvent;
use App\Models\SigningProvider;
use App\Models\SigningProviderSession;
use App\Models\SigningRequest;
use App\Models\User;
use App\Support\Branding\Brand;
use App\Support\Instance\InstanceSettings;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;
use Tests\Support\SigningConsentFixture;

/*
 * Signing at an external provider (ADR-046 amendment 2026-11-18, gap 12,
 * package S17): the provider framework with the fake driver and the fake
 * PDF engine (the CSC driver's protocol is tested in CscProviderTest, the
 * real engine's two steps in PdfEngineRealTest). Off by default; a company
 * configures its provider, a sender chooses it per signer, and the signer's
 * own PAdES signature becomes their revision of the PDF.
 */

function s17Switch(bool $on): void
{
    InstanceSetting::query()->updateOrCreate(['key' => InstanceSettings::SIGNING_PROVIDERS_ENABLED], ['value' => $on]);
    InstanceSettings::current()->forget();
}

function s17Provider(object $test, string $level = 'QES'): SigningProvider
{
    return app(ManageSigningProviders::class)->add($test->owner, $test->company, [
        'driver' => 'fake',
        'name' => 'Testtjänsten',
        'base_url' => 'https://underskrift.example/csc/v2',
        'client_id' => 'klient-123',
        'client_secret' => 'hemlig-klienthemlighet',
        'claimed_level' => $level,
    ]);
}

/** @return array{request: SigningRequest, token: string} */
function s17Send(object $test, string $check): array
{
    $sent = app(SendForSigning::class)($test->owner, $test->company, $test->quote, ['email' => 'anna@kontorshuset.test', 'verification' => $check]);

    return ['request' => $sent['request'], 'token' => basename((string) parse_url($sent['url'], PHP_URL_PATH))];
}

/** @return array<string, mixed> */
function s17Form(SigningRequest $request): array
{
    return ['name' => 'Anna Andersson', 'accept' => '1', 'method' => 'TYPED', 'typed_signature' => 'Anna Andersson', 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)];
}

/** The state in the provider address the interim page sends the browser to. */
function s17State(string $html): string
{
    preg_match('/url=([^"]+)"/', $html, $match);
    parse_str((string) parse_url(html_entity_decode($match[1] ?? ''), PHP_URL_QUERY), $query);

    return (string) ($query['state'] ?? '');
}

function s17StateOf(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) ($query['state'] ?? '');
}

/** Runs a signer through start, identification and authorisation; answers the last response. */
function s17SignThrough(object $test, array $sent): TestResponse
{
    SigningConsentFixture::give($sent['request']);
    $start = $test->post("/signera/{$sent['token']}/leverantor", s17Form($sent['request']->refresh()))->assertOk();
    $authorize = $test->get('/signera/leverantor/retur?'.http_build_query(['state' => s17State($start->getContent()), 'code' => 'kod-1']));
    $authorize->assertRedirect();

    return $test->get('/signera/leverantor/retur?'.http_build_query(['state' => s17StateOf((string) $authorize->headers->get('Location')), 'code' => 'kod-2']));
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    config(['nonox.signing.providers.allow_fake' => true]);
    FakeSignatureProvider::reset();
    Storage::fake('local');
    Mail::fake();
    $this->engine = FakePdfEngine::install();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB', ['email' => 'inkop@kontorshuset.test']);
    $this->quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-06-15',
        'until_date' => '2026-07-15',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]],
    ]);
});

afterEach(function () {
    InstanceSettings::current()->forget();
});

it('is off by default: no provider is offered and none can be chosen when sending', function () {
    $provider = s17Provider($this);

    expect(InstanceSettings::current()->signingProvidersEnabled())->toBeFalse()
        ->and(SignerChecks::providerOptions())->toBe([])
        ->and(fn () => s17Send($this, "PROVIDER:{$provider->id}"))->toThrow(ValidationException::class);

    // Switched on but without the PDF engine: still not offered.
    s17Switch(true);
    $this->engine->installed = false;

    expect(SignerChecks::providerOptions())->toBe([]);

    $this->engine->installed = true;

    expect(SignerChecks::providerOptions())->toBe([['value' => "PROVIDER:{$provider->id}", 'label' => 'Underskrift hos Testtjänsten']]);
});

it('is switched on in /hq, off by default', function () {
    $admin = User::factory()->create(['email' => 'hq-s17@example.test']);
    config(['nonox.hq_admins' => ['hq-s17@example.test'], 'app.admin_email' => null]);

    expect(InstanceSettings::current()->signingProvidersEnabled())->toBeFalse();

    $changed = app(UpdateInstanceSettings::class)($admin, ['waitlist_open' => true, 'registration_open' => false, 'signing_providers_enabled' => true]);

    expect($changed)->toBe([InstanceSettings::SIGNING_PROVIDERS_ENABLED])
        ->and(InstanceSettings::current()->signingProvidersEnabled())->toBeTrue();
});

it('stores the configuration with its secrets encrypted and hidden, and audits ids only', function () {
    $provider = s17Provider($this);
    $raw = DB::table('signing_providers')->where('id', $provider->id)->first();

    expect($raw->client_secret)->not->toContain('hemlig-klienthemlighet')
        ->and($raw->client_id)->not->toContain('klient-123')
        ->and($provider->fresh()->client_secret)->toBe('hemlig-klienthemlighet')
        ->and(array_keys($provider->toArray()))->not->toContain('client_secret')
        ->and(array_keys($provider->toArray()))->not->toContain('client_id')
        ->and($raw->service_name)->toBe('Testtjänst för underskrift');

    $audit = AuditLog::query()->where('action', 'signing.provider_added')->sole();

    expect($audit->payload)->toEqualCanonicalizing(['provider_id' => $provider->id, 'driver' => 'fake', 'claimed_level' => 'QES'])
        ->and(json_encode($audit->payload))->not->toContain('hemlig')
        ->and(json_encode($audit->payload))->not->toContain('underskrift.example');
});

it('is replaced, never edited: the trigger allows only retiring, which empties the secret', function () {
    $provider = s17Provider($this);

    expect(fn () => DB::transaction(fn () => DB::table('signing_providers')->where('id', $provider->id)->update(['name' => 'Annan'])))->toThrow(QueryException::class);

    app(ManageSigningProviders::class)->retire($this->owner, $this->company, $provider->id);
    $raw = DB::table('signing_providers')->where('id', $provider->id)->first();

    expect($raw->retired_at)->not->toBeNull()
        ->and($raw->client_secret)->toBeNull()
        ->and(fn () => DB::transaction(fn () => DB::table('signing_providers')->where('id', $provider->id)->update(['retired_at' => now()->addDay()])))->toThrow(QueryException::class)
        ->and(AuditLog::query()->where('action', 'signing.provider_retired')->sole()->payload)->toEqualCanonicalizing(['provider_id' => $provider->id, 'driver' => 'fake']);
});

it('lets a member with signing:settings add and retire a provider, and keeps the secret out of the component', function () {
    $component = Livewire::test(SigningProviderSettings::class, ['company' => $this->company])
        ->assertSee('Underskrift hos extern tjänst')
        ->assertSee('/signera/leverantor/retur')
        ->assertSee('inte påslagen')
        ->set('driver', 'fake')
        ->set('name', 'Testtjänsten')
        ->set('baseUrl', 'https://underskrift.example/csc/v2')
        ->set('clientId', 'klient-123')
        ->set('clientSecret', 'hemlig-klienthemlighet')
        ->set('claimedLevel', 'AES')
        ->call('add')
        ->assertHasNoErrors()
        ->assertSet('clientSecret', '')
        ->assertSee('Testtjänsten')
        ->assertSee('Avancerad elektronisk underskrift');

    expect(json_encode($component->snapshot))->not->toContain('hemlig-klienthemlighet');

    $provider = SigningProvider::query()->sole();

    $component->call('retire', $provider->id)->assertSee('Tidigare tjänster');

    expect($provider->fresh()->retired_at)->not->toBeNull();
});

it('empties the secret also when a provider is refused', function () {
    Livewire::test(SigningProviderSettings::class, ['company' => $this->company])
        ->set('driver', 'csc')
        ->set('name', 'Tjänsten')
        ->set('baseUrl', 'http://intern.example/csc/v2')
        ->set('clientId', 'klient')
        ->set('clientSecret', 'hemligt')
        ->call('add')
        ->assertHasErrors('baseUrl')
        ->assertSet('clientSecret', '');

    expect(SigningProvider::query()->count())->toBe(0);
});

it('refuses the settings to a member without signing:settings', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->actingAs($bookkeeper);

    Livewire::test(SigningProviderSettings::class, ['company' => $this->company])->assertForbidden();

    expect(fn () => app(ManageSigningProviders::class)->add($bookkeeper, $this->company, ['driver' => 'fake']))->toThrow(AuthorizationException::class);
});

it('signs at the provider: the signer\'s own signature becomes their revision, evidence is kept and the secrets go', function () {
    s17Switch(true);
    $provider = s17Provider($this);
    $sent = s17Send($this, "PROVIDER:{$provider->id}");
    auth()->logout();
    CurrentCompany::set($this->company);

    expect($sent['request']->verification)->toBe(SignerCheck::Provider)
        ->and($sent['request']->signing_provider_id)->toBe($provider->id);

    SigningConsentFixture::give($sent['request']);
    $this->get("/signera/{$sent['token']}")->assertOk()
        ->assertSee('Fortsätt till Testtjänsten')
        ->assertSee('Testtjänsten anger att underskriften är en kvalificerad elektronisk underskrift.')
        ->assertSee('NONOX anger inte själv någon nivå för underskriften.')
        ->assertSee(route('signing.provider.start', ['token' => $sent['token']]), false);

    $this->get('/signera/leverantor/retur?state=okand&code=x')->assertStatus(410);

    $start = $this->post("/signera/{$sent['token']}/leverantor", s17Form($sent['request']->refresh()))->assertOk()
        ->assertSee('Du skickas vidare till Testtjänsten')
        ->assertSee(FakeSignatureProvider::AUTHORIZE_URL, false);
    $session = SigningProviderSession::query()->sole();

    expect($session->step)->toBe(SigningProviderSession::SERVICE)
        ->and($session->state_hash)->toBe(hash('sha256', s17State($start->getContent())))
        ->and(json_encode(DB::table('signing_provider_sessions')->first()))->not->toContain('Anna Andersson');

    $authorize = $this->get('/signera/leverantor/retur?'.http_build_query(['state' => s17State($start->getContent()), 'code' => 'kod-1']));
    $authorize->assertRedirect();
    $session->refresh();

    expect((string) $authorize->headers->get('Location'))->toStartWith(FakeSignatureProvider::AUTHORIZE_URL)
        ->and($session->step)->toBe(SigningProviderSession::CREDENTIAL)
        ->and($session->certificate_name)->toBe('Test Testsson')
        ->and($session->digest_sha256)->toHaveLength(64)
        ->and(Storage::disk('local')->exists((string) $session->prepared_path))->toBeTrue();

    $this->get('/signera/leverantor/retur?'.http_build_query(['state' => s17StateOf((string) $authorize->headers->get('Location')), 'code' => 'kod-2']))
        ->assertOk()
        ->assertSee('Tack! Dokumentet är signerat.');

    $request = $sent['request']->refresh();
    $session->refresh();
    $version = SigningDocumentVersion::query()->where('kind', SigningDocumentVersion::AFTER_SIGNER)->sole();
    $original = SigningDocumentVersion::query()->where('kind', SigningDocumentVersion::ORIGINAL)->sole();
    $bytes = (string) Storage::disk('local')->get($version->path);

    expect($request->status)->toBe(SigningStatus::Signed)
        ->and($session->step)->toBe(SigningProviderSession::DONE)
        ->and($session->signed_version_id)->toBe($version->id)
        ->and($session->getRawOriginal('secrets'))->toBeNull()
        ->and($session->state_hash)->toBeNull()
        ->and($session->prepared_path)->toBeNull()
        ->and($version->signing_request_id)->toBe($request->id)
        ->and(str_starts_with($bytes, (string) Storage::disk('local')->get($original->path)))->toBeTrue()
        ->and($bytes)->toContain("% nonox-fake-signer NonoxSigner{$request->id} {$session->certificate_sha256}")
        ->and(Storage::disk('local')->allFiles("crm-signing/{$this->company->id}/provider"))->toBe([]);

    $event = SigningEvent::query()->where('signing_request_id', $request->id)->where('event', SigningEventType::ProviderSigned->value)->sole();

    expect($event->payload)->toMatchArray([
        'provider_session_id' => $session->id,
        'claimed_level' => 'QES',
        'certificate_sha256' => $session->certificate_sha256,
        'shown_sha256' => $original->sha256,
        'version_sha256' => $version->sha256,
    ]);

    expect(app(ChainVerifier::class)->verify($this->company)['problems'])->toBe([]);

    $audits = AuditLog::query()->whereIn('action', ['signing.provider_started', 'signing.provider_signed'])->get();

    expect($audits)->toHaveCount(2)
        ->and(json_encode($audits->pluck('payload')))->not->toContain('Anna')
        ->and(json_encode($audits->pluck('payload')))->not->toContain('Testsson');

    // The signing certificate names the provider's claim, never one of NONOX's own.
    $html = view('signing.certificate', ['request' => $request->load(['events', 'providerSignature']), 'company' => $this->company, 'brand' => Brand::for($this->company), 'title' => 'Offert'])->render();

    expect($html)->toContain('Testtjänsten anger att underskriften är en kvalificerad elektronisk underskrift.')
        ->and($html)->toContain('Test Testsson');
});

it('refuses a provider signer at the ordinary signing form', function () {
    s17Switch(true);
    $provider = s17Provider($this);
    $sent = s17Send($this, "PROVIDER:{$provider->id}");
    SigningConsentFixture::give($sent['request']);

    expect(fn () => app(SignAtLink::class)->sign($sent['request']->refresh(), s17Form($sent['request']), '127.0.0.1', 'test'))
        ->toThrow(ValidationException::class, 'skrivs under hos en extern tjänst');
    expect($sent['request']->refresh()->status)->toBe(SigningStatus::Pending);
});

it('refuses a callback in another browser, and ends the run', function () {
    s17Switch(true);
    $provider = s17Provider($this);
    $sent = s17Send($this, "PROVIDER:{$provider->id}");
    auth()->logout();
    CurrentCompany::set($this->company);
    SigningConsentFixture::give($sent['request']);
    $start = $this->post("/signera/{$sent['token']}/leverantor", s17Form($sent['request']->refresh()))->assertOk();

    $this->flushSession();
    $this->get('/signera/leverantor/retur?'.http_build_query(['state' => s17State($start->getContent()), 'code' => 'kod-1']))
        ->assertRedirect("/signera/{$sent['token']}");

    $session = SigningProviderSession::query()->sole();

    expect($session->step)->toBe(SigningProviderSession::FAILED)
        ->and($session->failure)->toBe(ProviderFailure::WRONG_BROWSER)
        ->and($session->getRawOriginal('secrets'))->toBeNull()
        ->and($sent['request']->refresh()->status)->toBe(SigningStatus::Pending);
});

it('ends the run when the signer cancels at the provider or the provider signs something else', function (string $case) {
    s17Switch(true);
    $provider = s17Provider($this);
    $sent = s17Send($this, "PROVIDER:{$provider->id}");
    auth()->logout();
    CurrentCompany::set($this->company);

    if ($case === 'declined') {
        SigningConsentFixture::give($sent['request']);
        $start = $this->post("/signera/{$sent['token']}/leverantor", s17Form($sent['request']->refresh()))->assertOk();
        $this->get('/signera/leverantor/retur?'.http_build_query(['state' => s17State($start->getContent()), 'error' => 'access_denied']))
            ->assertRedirect("/signera/{$sent['token']}");
        $this->get("/signera/{$sent['token']}")->assertSee('Underskriften avbröts hos tjänsten. Ingenting har skrivits under.');
    } else {
        FakeSignatureProvider::$wrongSignatureNext = true;
        s17SignThrough($this, $sent)->assertRedirect("/signera/{$sent['token']}");
    }

    $session = SigningProviderSession::query()->sole();

    expect($session->step)->toBe(SigningProviderSession::FAILED)
        ->and($session->failure)->toBe($case === 'declined' ? ProviderFailure::DECLINED : ProviderFailure::SIGNATURE_INVALID)
        ->and($session->prepared_path)->toBeNull()
        ->and($sent['request']->refresh()->status)->toBe(SigningStatus::Pending)
        ->and(SigningDocumentVersion::query()->where('kind', SigningDocumentVersion::AFTER_SIGNER)->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles("crm-signing/{$this->company->id}/provider"))->toBe([]);
})->with(['declined', 'wrong signature']);

it('refuses when another signer changed the document while the signer was at the provider', function () {
    s17Switch(true);
    $provider = s17Provider($this);
    $sent = s17Send($this, "PROVIDER:{$provider->id}");
    auth()->logout();
    CurrentCompany::set($this->company);
    SigningConsentFixture::give($sent['request']);
    $start = $this->post("/signera/{$sent['token']}/leverantor", s17Form($sent['request']->refresh()))->assertOk();

    // A parallel signer's revision, as FieldStamper would store it.
    Storage::disk('local')->put('crm-signing/ny.pdf', '%PDF-1.7 ny');
    CurrentCompany::run($this->company, fn () => SigningDocumentVersion::create(['envelope_id' => $sent['request']->envelope_id, 'kind' => SigningDocumentVersion::AFTER_SIGNER, 'path' => 'crm-signing/ny.pdf', 'sha256' => hash('sha256', '%PDF-1.7 ny'), 'bytes' => 11]));

    $this->get('/signera/leverantor/retur?'.http_build_query(['state' => s17State($start->getContent()), 'code' => 'kod-1']))
        ->assertRedirect("/signera/{$sent['token']}");

    expect(SigningProviderSession::query()->sole()->failure)->toBe(ProviderFailure::DOCUMENT_CHANGED)
        ->and($sent['request']->refresh()->status)->toBe(SigningStatus::Pending);
});

it('tells the signer to contact the sender when the provider is retired or the switch is off', function (string $case) {
    s17Switch(true);
    $provider = s17Provider($this);
    $sent = s17Send($this, "PROVIDER:{$provider->id}");

    if ($case === 'retired') {
        app(ManageSigningProviders::class)->retire($this->owner, $this->company, $provider->id);
    } else {
        s17Switch(false);
    }

    auth()->logout();
    CurrentCompany::set($this->company);
    SigningConsentFixture::give($sent['request']);

    $this->get("/signera/{$sent['token']}")->assertOk()
        ->assertSee('Underskrift hos Testtjänsten går inte att använda just nu. Kontakta avsändaren.')
        ->assertDontSee('Fortsätt till Testtjänsten');

    $this->post("/signera/{$sent['token']}/leverantor", s17Form($sent['request']->refresh()))->assertRedirect("/signera/{$sent['token']}");

    expect(SigningProviderSession::query()->count())->toBe(0);
})->with(['retired', 'switched off']);

it('refuses fields of their own for a provider signer, and another company\'s provider', function () {
    s17Switch(true);
    $provider = s17Provider($this);

    expect(fn () => app(SendForSigning::class)($this->owner, $this->company, $this->quote, [
        'email' => 'anna@kontorshuset.test',
        'verification' => "PROVIDER:{$provider->id}",
        'fields' => [['signer' => 0, 'type' => 'SIGNATURE', 'page' => 1, 'x' => 1000, 'y' => 1000, 'width' => 30000, 'height' => 6000, 'label' => 'Underskrift']],
    ]))->toThrow(ValidationException::class, 'kan inte ha egna fält');

    [$otherOwner, $other] = Bookkeeping::company();
    $foreign = CurrentCompany::run($other, fn () => app(ManageSigningProviders::class)->add($otherOwner, $other, ['driver' => 'fake', 'name' => 'Annans', 'base_url' => 'https://annan.example/csc/v2', 'client_id' => 'x', 'client_secret' => 'y', 'claimed_level' => 'QES']));
    CurrentCompany::set($this->company);

    expect(fn () => s17Send($this, "PROVIDER:{$foreign->id}"))->toThrow(ValidationException::class, 'kan inte användas just nu');
});

it('fixes the provider on the signer and keeps a finished run unless its signer goes', function () {
    s17Switch(true);
    $provider = s17Provider($this);
    $sent = s17Send($this, "PROVIDER:{$provider->id}");
    auth()->logout();
    CurrentCompany::set($this->company);
    s17SignThrough($this, $sent)->assertOk();
    $session = SigningProviderSession::query()->sole();

    expect(fn () => DB::transaction(fn () => DB::table('crm_signing_requests')->where('id', $sent['request']->id)->update(['signing_provider_id' => null])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_provider_sessions')->where('id', $session->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_provider_sessions')->where('id', $session->id)->update(['certificate_name' => 'Någon annan'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_provider_sessions')->where('id', $session->id)->update(['secrets' => 'x'])))->toThrow(QueryException::class);
});

it('starts one run per signer: a new start ends the earlier one', function () {
    s17Switch(true);
    $provider = s17Provider($this);
    $sent = s17Send($this, "PROVIDER:{$provider->id}");
    auth()->logout();
    CurrentCompany::set($this->company);
    SigningConsentFixture::give($sent['request']);

    $this->post("/signera/{$sent['token']}/leverantor", s17Form($sent['request']->refresh()))->assertOk();
    $this->post("/signera/{$sent['token']}/leverantor", s17Form($sent['request']->refresh()))->assertOk();

    expect(SigningProviderSession::query()->orderBy('id')->pluck('step')->all())->toBe([SigningProviderSession::FAILED, SigningProviderSession::SERVICE])
        ->and(SigningProviderSession::query()->orderBy('id')->first()->failure)->toBe(ProviderFailure::SUPERSEDED);
});

it('offers the provider in the signing panel and the send wizard while it can be used', function () {
    s17Switch(true);
    $provider = s17Provider($this);

    Livewire::test(SigningPanel::class, ['quoteId' => $this->quote->id])
        ->assertSee('Underskrift hos Testtjänsten')
        ->assertSeeHtml("value=\"PROVIDER:{$provider->id}\"");
})->skip(fn () => ! class_exists(SigningPanel::class), 'No panel.');

it('has one fixed return address for every provider', function () {
    expect(SignWithProvider::redirectUri())->toEndWith('/signera/leverantor/retur');
});

it('lets a finished run go with its envelope after retention, and prunes runs that never finished', function () {
    s17Switch(true);
    $provider = s17Provider($this);
    $this->travelTo(CarbonImmutable::parse('2012-03-01 10:00:00'));
    $sent = s17Send($this, "PROVIDER:{$provider->id}");
    auth()->logout();
    CurrentCompany::set($this->company);
    s17SignThrough($this, $sent)->assertOk();

    // A second run that was abandoned at the provider, with its prepared file.
    Storage::disk('local')->put("crm-signing/{$this->company->id}/provider/kvar.pdf", '%PDF-1.7');
    $abandoned = CurrentCompany::run($this->company, fn () => SigningProviderSession::create(['signing_request_id' => $sent['request']->id, 'provider_id' => $provider->id, 'step' => SigningProviderSession::SERVICE, 'state_hash' => str_repeat('a', 64), 'secrets' => ['token' => 'x'], 'prepared_path' => "crm-signing/{$this->company->id}/provider/kvar.pdf", 'shown_sha256' => str_repeat('0', 64), 'claimed_level' => 'QES', 'provider_name' => 'Testtjänsten', 'expires_at' => now()->addMinutes(30)]));

    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    app(PruneSigningLinks::class)->run(CarbonImmutable::now());

    expect(SigningProviderSession::query()->whereKey($abandoned->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists("crm-signing/{$this->company->id}/provider/kvar.pdf"))->toBeFalse()
        ->and(SigningRequest::query()->whereKey($sent['request']->id)->exists())->toBeFalse()
        ->and(SigningProviderSession::query()->count())->toBe(0);
});
