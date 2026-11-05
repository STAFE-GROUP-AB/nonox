<?php

declare(strict_types=1);

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Vat\RefreshVatSubmission;
use App\Actions\Vat\SendVatReturnToSkatteverket;
use App\Domain\Company\VatPeriod;
use App\Domain\Vat\VatSubmissionStatus;
use App\Http\Controllers\Skatteverket\SkatteverketAuthController;
use App\Livewire\Vat\SkatteverketPanel;
use App\Livewire\Vat\VatReturns;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\SkatteverketToken;
use App\Models\VatSubmission;
use App\Support\Skatteverket\Moms\MomsPayload;
use App\Support\Skatteverket\Redovisare;
use App\Support\Skatteverket\SkatteverketAuth;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

const SKV_API = 'https://api.test.skatteverket.se/momsdeklaration/v1';
const SKV_TOKEN_URL = 'https://peroauth2.test.skatteverket.se/oauth2/v1/per/token';
const SKV_SIGNING = 'https://sso.test.skatteverket.se/ms/ms_web/page.do?id=1';

beforeEach(function () {
    config([
        'services.skatteverket.environment' => 'test',
        'services.skatteverket.oauth_client_id' => 'oauth-id',
        'services.skatteverket.oauth_client_secret' => 'oauth-secret',
        'services.skatteverket.api_client_id' => 'gw-id',
        'services.skatteverket.api_client_secret' => 'gw-secret',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-02-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::Monthly, 'org_number' => '556334-3689'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
    $this->january = CarbonImmutable::parse('2026-01-01');

    // January: 1 000 kr sales at 25 % and a purchase with 200 kr input VAT.
    foreach ([
        [['account' => 1930, 'debit_ore' => 125_000], ['account' => 3001, 'credit_ore' => 100_000], ['account' => 2611, 'credit_ore' => 25_000]],
        [['account' => 4010, 'debit_ore' => 80_000], ['account' => 2641, 'debit_ore' => 20_000], ['account' => 1930, 'credit_ore' => 100_000]],
    ] as $index => $rows) {
        app(PostVoucher::class)($this->owner, app(SaveDraftVoucher::class)($this->owner, $this->company, ['voucher_date' => '2026-01-1'.$index, 'description' => 'Test', 'rows' => $rows]));
    }
});

function signedInWithSkatteverket(): void
{
    SkatteverketToken::query()->create([
        'user_id' => test()->owner->id,
        'access_token' => 'person-token',
        'refresh_token' => 'person-refresh',
        'scope' => 'momsdeklaration agd agdredovisningperiod ska kundhandelser',
        'expires_at' => now()->addHour(),
        'refresh_expires_at' => now()->addMinutes(65),
    ]);
}

it('turns the company and period into Skatteverket\'s identifiers', function () {
    expect(Redovisare::of('556334-3689'))->toBe('165563343689')
        ->and(Redovisare::of('19121212-1212'))->toBe('191212121212')
        ->and(Redovisare::of('121212-1212', CarbonImmutable::parse('2026-02-15')))->toBe('201212121212')
        ->and(Redovisare::of('121212+1212', CarbonImmutable::parse('2026-02-15')))->toBe('191212121212')
        ->and(Redovisare::of('811218-9876', CarbonImmutable::parse('2026-02-15')))->toBe('198112189876')
        ->and(Redovisare::of('556334-3680'))->toBeNull();
});

it('maps the boxes to Skatteverket\'s fields, with box 49 always present', function () {
    expect(MomsPayload::from([5 => 1000, 10 => 250, 48 => 200, 49 => 50, 20 => 0]))
        ->toBe(['momspliktigForsaljning' => 1000, 'momsForsaljningUtgaendeHog' => 250, 'ingaendeMomsAvdrag' => 200, 'summaMoms' => 50])
        ->and(MomsPayload::from([]))->toBe(['summaMoms' => 0])
        ->and(MomsPayload::boxes(['summaMoms' => -75, 'ingaendeMomsAvdrag' => 75, 'okand' => 3]))->toBe([48 => 75, 49 => -75]);
});

it('signs in with Skatteverket once per state and stores the tokens encrypted', function () {
    $start = $this->get(route('skatteverket.signIn', ['tillbaka' => '/moms?period=2026-01-01']));
    $flow = session(SkatteverketAuthController::SESSION);

    $start->assertRedirect();
    expect($start->headers->get('Location'))
        ->toStartWith('https://peroauth2.test.skatteverket.se/oauth2/v1/per/authorize?')
        ->toContain('response_type=code')
        ->toContain('client_id=oauth-id')
        ->toContain('state='.$flow['state'])
        ->toContain('scope=momsdeklaration%20agd%20agdredovisningperiod%20ska%20kundhandelser')
        ->toContain('redirect_uri='.rawurlencode(route('skatteverket.callback')));

    Http::fake([SKV_TOKEN_URL => Http::response(['access_token' => 'the-token', 'expires_in' => 3600, 'token_type' => 'Bearer', 'refresh_token' => 'the-refresh', 'scope' => 'momsdeklaration'])]);

    $this->withSession([SkatteverketAuthController::SESSION => $flow])
        ->get(route('skatteverket.callback', ['code' => 'abc', 'state' => $flow['state']]))
        ->assertRedirect('/moms?period=2026-01-01');

    Http::assertSent(fn (Request $request): bool => $request->url() === SKV_TOKEN_URL
        && $request['grant_type'] === 'authorization_code' && $request['code'] === 'abc'
        && $request['client_id'] === 'oauth-id' && $request['client_secret'] === 'oauth-secret'
        && $request['redirect_uri'] === route('skatteverket.callback'));

    $token = SkatteverketToken::query()->sole();
    expect($token->access_token)->toBe('the-token')
        ->and(DB::table('skatteverket_tokens')->value('access_token'))->not->toContain('the-token')
        ->and(SkatteverketAuth::isSignedIn($this->owner))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'skatteverket.signed_in')->count())->toBe(1);

    // The flow is used up.
    $this->get(route('skatteverket.callback', ['code' => 'abc', 'state' => $flow['state']]))->assertRedirect(route('vat.index', absolute: false));
    Http::assertSentCount(1);
});

it('refuses a callback with a wrong state, another person\'s flow, an old flow or a link off the site', function () {
    Http::fake();
    $flow = ['state' => 'right', 'user_id' => $this->owner->id, 'back' => '/moms', 'at' => now()->getTimestamp()];

    $this->withSession([SkatteverketAuthController::SESSION => $flow])->get(route('skatteverket.callback', ['code' => 'c', 'state' => 'wrong']));
    $this->withSession([SkatteverketAuthController::SESSION => [...$flow, 'user_id' => $this->owner->id + 1]])->get(route('skatteverket.callback', ['code' => 'c', 'state' => 'right']));
    $this->withSession([SkatteverketAuthController::SESSION => [...$flow, 'at' => now()->subMinutes(11)->getTimestamp()]])->get(route('skatteverket.callback', ['code' => 'c', 'state' => 'right']));
    $this->withSession([SkatteverketAuthController::SESSION => [...$flow, 'back' => '//evil.example']])->get(route('skatteverket.callback', ['error' => 'access_denied', 'state' => 'right']))
        ->assertRedirect(route('vat.index', absolute: false));

    Http::assertNothingSent();
    expect(SkatteverketToken::query()->count())->toBe(0);
});

it('is not offered when the installation has no keys', function () {
    config(['services.skatteverket.api_client_secret' => '']);

    $this->get(route('skatteverket.signIn'))->assertNotFound();
    Livewire::test(VatReturns::class, ['period' => '2026-01-01'])->assertDontSee('Lämna deklarationen till Skatteverket');
});

it('shows the panel with what sending means, and asks for BankID first', function () {
    Livewire::test(VatReturns::class, ['period' => '2026-01-01'])->assertSee('Lämna deklarationen till Skatteverket');

    Livewire::test(SkatteverketPanel::class, ['fiscalYearId' => $this->year->id, 'start' => '2026-01-01'])
        ->assertSee('Testmiljö')
        ->assertSee('den är lämnad först när någon med behörighet har skrivit under den hos Skatteverket')
        ->assertSee('Lämna momsdeklaration på Mina sidor')
        ->assertSee('Skatteverket loggar varje anrop')
        ->assertSee('Logga in hos Skatteverket med BankID')
        ->assertDontSee('Skicka till Skatteverket')
        ->call('send')
        ->assertDispatched('toast', message: 'Logga in hos Skatteverket med BankID först.');
});

it('lets Skatteverket check the return with the right headers and saves nothing', function () {
    signedInWithSkatteverket();
    Http::fake([SKV_API.'/kontrollera/*' => Http::response(['status' => 'WARNING', 'resultat' => [['status' => 'WARNING', 'kod' => 'RK009', 'beskrivning' => 'Kontrollera momsen.']]])]);

    Livewire::test(SkatteverketPanel::class, ['fiscalYearId' => $this->year->id, 'start' => '2026-01-01'])
        ->call('checkReturn')
        ->assertDispatched('toast', message: 'Skatteverket varnar för något i deklarationen. Läs varningarna innan du skickar.')
        ->assertSee('RK009')
        ->assertSee('Kontrollera momsen.');

    Http::assertSent(fn (Request $request): bool => $request->url() === SKV_API.'/kontrollera/165563343689/202601'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer person-token')
        && $request->hasHeader('client_id', 'gw-id') && $request->hasHeader('client_secret', 'gw-secret')
        && strlen($request->header('skv_client_correlation_id')[0] ?? '') === 36
        && $request->data() === ['momspliktigForsaljning' => 1000, 'momsForsaljningUtgaendeHog' => 250, 'ingaendeMomsAvdrag' => 200, 'summaMoms' => 50]);

    expect(VatSubmission::query()->count())->toBe(0);
});

it('sends the draft locked for signing and shows Skatteverket\'s signing link only', function () {
    signedInWithSkatteverket();
    Http::fake([SKV_API.'/utkast/*' => Http::sequence()
        ->push(['sparad' => true, 'last' => true, 'kontrollResultat' => ['status' => 'OK', 'resultat' => []], 'signeringsLank' => SKV_SIGNING])
        ->push(['sparad' => true, 'last' => true, 'kontrollResultat' => ['status' => 'OK', 'resultat' => []], 'signeringsLank' => 'https://skatteverket.se.evil.example/x'])]);

    Livewire::test(SkatteverketPanel::class, ['fiscalYearId' => $this->year->id, 'start' => '2026-01-01'])
        ->call('send')
        ->assertDispatched('toast', message: 'Utkastet är skickat och låst hos Skatteverket. Skriv under hos Skatteverket för att lämna deklarationen.')
        ->assertSee('Skriv under hos Skatteverket')
        ->assertSee(SKV_SIGNING, escape: false);

    Http::assertSent(fn (Request $request): bool => $request->url() === SKV_API.'/utkast/165563343689/202601?lock=true');

    $submission = VatSubmission::query()->sole();
    expect($submission->status)->toBe(VatSubmissionStatus::ReadyToSign)
        ->and($submission->boxes[49])->toBe(50)
        ->and($submission->sent_by)->toBe($this->owner->id)
        ->and(AuditLog::query()->where('action', 'vat.skatteverket_sent')->sole()->payload)->toEqual(['period_end' => '2026-01-31', 'locked' => true, 'status' => 'OK']);

    // A link to any other site is never shown.
    app(SendVatReturnToSkatteverket::class)($this->owner, $this->year, $this->january);
    expect(VatSubmission::query()->sole()->signing_link)->toBeNull();
});

it('shows Skatteverket\'s own error text, and ends the sign-in on 401', function () {
    signedInWithSkatteverket();
    Http::fake([SKV_API.'/utkast/*' => Http::sequence()
        ->push(['kod' => '403', 'meddelandeTillAnvandare' => 'Behörighet saknas för aktör att företräda redovisare.'], 403)
        ->push(['error' => 'Invalid token'], 401)]);

    Livewire::test(SkatteverketPanel::class, ['fiscalYearId' => $this->year->id, 'start' => '2026-01-01'])
        ->call('send')
        ->assertDispatched('toast', message: 'Behörighet saknas för aktör att företräda redovisare.');

    expect(VatSubmission::query()->count())->toBe(0)->and(SkatteverketToken::query()->count())->toBe(1);

    Livewire::test(SkatteverketPanel::class, ['fiscalYearId' => $this->year->id, 'start' => '2026-01-01'])
        ->call('send')
        ->assertDispatched('toast', message: 'Inloggningen hos Skatteverket har gått ut. Logga in hos Skatteverket igen.');

    expect(SkatteverketToken::query()->count())->toBe(0);
});

it('fetches the receipt and the decided amounts, keeping no names', function () {
    signedInWithSkatteverket();
    Http::fake([
        SKV_API.'/inlamnat/*' => Http::response([['kvittensnummer' => 'K-123', 'redovisningsperiod' => '202601', 'redovisare' => '165563343689', 'inlamnadTidpunkt' => '2026-02-10T09:15:00Z', 'inlamnadAv' => 'Tolvan Tolvansson', 'momsuppgift' => ['summaMoms' => 50]]]),
        SKV_API.'/beslutat/*' => Http::response(['redovisningsperiod' => '202601', 'redovisare' => '165563343689', 'beslutadTidpunkt' => '2026-02-12T08:00:00Z', 'momsuppgift' => ['summaMoms' => 60, 'momspliktigForsaljning' => 1040]]),
    ]);

    $submission = app(RefreshVatSubmission::class)($this->owner, $this->year, $this->january);

    expect($submission->status)->toBe(VatSubmissionStatus::Submitted)
        ->and($submission->receipt_number)->toBe('K-123')
        ->and($submission->decided_boxes)->toBe([5 => 1040, 49 => 60])
        ->and(json_encode(VatSubmission::query()->sole()->getAttributes()))->not->toContain('Tolvan');

    Livewire::test(SkatteverketPanel::class, ['fiscalYearId' => $this->year->id, 'start' => '2026-01-01'])
        ->assertSee('Kvittens K-123')
        ->assertSee('Skatteverkets beslut skiljer sig från det NONOX skickade');
});

it('treats a period without a decision as filed and not decided', function () {
    signedInWithSkatteverket();
    Http::fake([
        SKV_API.'/inlamnat/*' => Http::response([['kvittensnummer' => 'K-9', 'inlamnadTidpunkt' => '2026-02-10T09:15:00Z', 'momsuppgift' => ['summaMoms' => 50]]]),
        SKV_API.'/beslutat/*' => Http::response(['kod' => '404', 'meddelandeTillAnvandare' => 'Inget beslut finns.'], 404),
    ]);

    $submission = app(RefreshVatSubmission::class)($this->owner, $this->year, $this->january);

    expect($submission->receipt_number)->toBe('K-9')->and($submission->decided_boxes)->toBeNull();
});

it('refreshes an expired token once and asks for BankID when the refresh token is gone too', function () {
    SkatteverketToken::query()->create(['user_id' => $this->owner->id, 'access_token' => 'old', 'refresh_token' => 'refresh', 'scope' => 'momsdeklaration', 'expires_at' => now()->subMinute(), 'refresh_expires_at' => now()->addMinutes(30)]);
    Http::fake([SKV_TOKEN_URL => Http::response(['access_token' => 'new', 'expires_in' => 3600, 'refresh_token' => 'refresh-2'])]);

    expect(SkatteverketAuth::accessToken($this->owner))->toBe('new');
    Http::assertSent(fn (Request $request): bool => $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'refresh');

    SkatteverketToken::query()->update(['expires_at' => now()->subMinute(), 'refresh_expires_at' => now()->subMinute()]);
    expect(SkatteverketAuth::accessToken($this->owner))->toBeNull()->and(SkatteverketToken::query()->count())->toBe(0);
});

it('lets only those who may submit VAT send, and everyone with the VAT page check', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $accountant = Bookkeeping::member($this->company, 'accountant');

    expect(fn () => app(SendVatReturnToSkatteverket::class)($auditor, $this->year, $this->january))->toThrow(AuthorizationException::class)
        ->and($accountant->hasTeamPermission($this->company, 'vat:submit'))->toBeTrue();
});

it('prunes sign-ins with Skatteverket that can no longer be used', function () {
    signedInWithSkatteverket();
    SkatteverketToken::query()->create(['user_id' => Bookkeeping::member($this->company, 'accountant')->id, 'access_token' => 'x', 'refresh_token' => null, 'scope' => 'ska', 'expires_at' => now()->subMinute()]);

    $this->artisan('privacy:prune')->assertSuccessful();

    expect(SkatteverketToken::query()->pluck('user_id')->all())->toBe([$this->owner->id]);
});
