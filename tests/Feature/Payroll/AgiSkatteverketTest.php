<?php

declare(strict_types=1);

use App\Http\Controllers\Skatteverket\SkatteverketAuthController;
use App\Models\AuditLog;
use App\Models\SkatteverketToken;
use App\Models\User;
use App\Modules\Payroll\Actions\AgiSkatteverketUploads;
use App\Modules\Payroll\Actions\AgiSubmissions;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\AgiPage;
use App\Modules\Payroll\Livewire\AgiSkatteverketPanel;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\AgiUpload;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Support\Skatteverket\Agd\AgdResult;
use App\Support\Skatteverket\SkatteverketAuth;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * ADR-054 stage 2: the arbetsgivardeklaration through Skatteverket's APIs.
 * The responses are built from the RAML examples of AGD inlämning 1.7.7
 * (library 1.0.13) and hantera redovisningsperiod 1.2.8 (library 1.0.26).
 */
const AGD_FILING = 'https://api.test.skatteverket.se/arbetsgivardeklaration/inlamning/v1';
const AGD_PERIOD = 'https://api.test.skatteverket.se/arbetsgivardeklaration/hanteraredovisningsperiod/v1';
const AGD_REVIEW_LINK = 'https://sso.test.skatteverket.se/ms/ms_web/page.do?demo=true&link=signera-ag&redovisningsperiod=202604';

beforeEach(function () {
    config([
        'services.skatteverket.environment' => 'test',
        'services.skatteverket.oauth_client_id' => 'oauth-id',
        'services.skatteverket.oauth_client_secret' => 'oauth-secret',
        'services.skatteverket.api_client_id' => 'gw-id',
        'services.skatteverket.api_client_secret' => 'gw-secret',
        'services.skatteverket.pilot_users' => '',
    ]);
    // Nothing in these tests may reach the real Skatteverket.
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-05-04 10:00:00'));
    Storage::fake('local');
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['phone' => '08-123 456 78', 'org_number' => '556334-3689'])->save();
    Payroll::setUp($this->company, $this->owner);

    foreach (['agi.schema_version' => ['version' => '1.1'], 'agi.deadlines' => ['turnover_up_to_40_msek' => ['2026-01-19', '2026-05-12'], 'turnover_over_40_msek_declare_day' => 26]] as $key => $value) {
        PayrollRuleVersion::create(['key' => $key, 'valid_from' => '2026-01-01', 'value' => $value, 'source_url' => 'https://example.test'])
            ->forceFill(['verified_by' => 'Testperson', 'verified_at' => now()])->save();
    }

    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Payroll::employee($this->owner, $this->company);
    $runs = app(PayrollRuns::class);
    $run = $runs->create($this->owner, $this->company, '2026-04');
    $runs->calculate($this->owner, $run);
    $runs->approve(Bookkeeping::member($this->company, 'admin'), $run->fresh());
    $this->submission = app(AgiSubmissions::class)->generate($this->owner, $this->company, '2026-04');
    $this->uploads = app(AgiSkatteverketUploads::class);
});

function agdSignedIn(?User $user = null, string $scope = 'momsdeklaration agd agdredovisningperiod'): void
{
    SkatteverketToken::query()->create([
        'user_id' => ($user ?? test()->owner)->id,
        'access_token' => 'person-token',
        'refresh_token' => 'person-refresh',
        'scope' => $scope,
        'expires_at' => now()->addHour(),
        'refresh_expires_at' => now()->addMinutes(65),
    ]);
}

function agdUpload(string $status, int $uploadId = 1100130, array $extra = []): AgiUpload
{
    return AgiUpload::query()->forceCreate([
        'company_id' => test()->company->id,
        'agi_submission_id' => test()->submission->id,
        'period' => '2026-04',
        'upload_id' => $uploadId,
        'sha256' => test()->submission->sha256,
        'status' => $status,
        'uploaded_by' => test()->owner->id,
        'uploaded_at' => now()->subHour(),
        ...$extra,
    ]);
}

function agdPanel(): Testable
{
    return Livewire::test(AgiSkatteverketPanel::class);
}

/** grunddata_example.json with the state and signed flag of the test. */
function agdGrunddata(string $state = 'CALCULATED', bool $signed = false, bool $correcting = false): array
{
    return ['grunddata' => [
        'period' => ['period' => '202604', 'andringstid' => '2026-05-04T08:58:20+00:00', 'persorgnr' => '165563343689', 'deklarationsdatum' => '2026-05-12', 'betalningsdatum' => '2026-05-12', 'betalningsanstandsdatum' => '', 'deklarationsanstandsdatum' => '', 'sasongsredovisare' => false],
        'statusEgetUtrymme' => $state, 'signerad' => $signed, 'omprovningIniterad' => $correcting, 'statusUnderlag' => 'OK', 'statusHU' => 'OK', 'antalIUstatusOK' => 1, 'antalIUstatusFel' => 0, 'antalIUstatusVarning' => 0, 'antalIUstatusInfo' => 0,
    ]];
}

/** exempel_kontrollresultat_DONE_SUCCESS.json */
function agdResultSuccess(): array
{
    return [
        'status' => 'DONE_SUCCESS', 'inlamnad' => '2022-01-10T15:28:03+0100', 'antalUppgifter' => 2, 'kontrolleradeUppgifter' => 2,
        'kontrollrapport' => [
            'filstorlek' => 3239, 'filnamn' => 'tmp_220110_152803.352_h05kzw4z', 'inkanal' => 'MASK', 'schemaVersion' => '1.1', 'inlamnad' => '2022-01-10T15:28:03+0100',
            'bearbetningsfel' => [], 'valideringsfel' => [],
            'tekniskKontakt' => ['programnamn' => 'IDAG-verkstan AB', 'namn' => 'Ville Vessla', 'telefon' => '23-2-4-244454', 'epost' => 'siv.karlsson@sonjasfrohandel.se'],
            'inlamnadAv' => '197209223275',
            'redovisningsperioder' => [['arbetsgivare' => '165563343689', 'arbetsgivareKontakter' => [['arbetsgivare' => '165563343689', 'epost' => 'siv.karlsson@sonjasfrohandel.se', 'namn' => 'Ville Vessla', 'sakomrade' => 'Rymdteknik', 'telefon' => '23-2-4-244454']], 'perioder' => [['period' => '202604', 'kontrollfel' => [], 'antalIU' => 1, 'antalHU' => 1, 'antalFel' => 0, 'antalAvvisandeFel' => 0, 'antalVarningar' => 0]]]],
            'status' => 'OK', 'totaltAntalHU' => 1, 'totaltAntalIU' => 1, 'antalFel' => 0, 'antalVarningar' => 0, 'antalAvvisandefel' => 0,
        ],
    ];
}

/** DONE_FAILED: the kontrollfel shape of kontrollresultat.json, texts from the RAML's kontrollsvar examples. */
function agdResultFailed(): array
{
    $body = agdResultSuccess();
    $body['status'] = 'DONE_FAILED';
    $body['kontrollrapport']['status'] = 'FAILED';
    $body['kontrollrapport']['antalFel'] = 1;
    $body['kontrollrapport']['antalVarningar'] = 1;
    $body['kontrollrapport']['redovisningsperioder'][0]['perioder'][0]['kontrollfel'] = [
        ['felkategori' => 'SAMBAND', 'uppgiftsTyp' => 'IU', 'textNyckel' => 'S_175', 'textTyp' => 'FEL', 'identifierare' => '198508812396', 'specifikationsnummer' => 1, 'felmeddelande' => 'Det får bara finnas ett skattefält på respektive individuppgift. På den här individuppgiften finns flera skattefält. (S_175)', 'felstatus' => 'STOPP'],
        ['felkategori' => 'RIMLIGHET', 'uppgiftsTyp' => 'IU', 'textNyckel' => 'R_006', 'textTyp' => 'FEL', 'identifierare' => '198508812396', 'specifikationsnummer' => 1, 'felmeddelande' => 'Beloppet i fältet "Avdragen preliminär skatt" är högre än 10 000 000 kronor. (R_006)', 'felstatus' => 'ARENDE'],
    ];

    return $body;
}

it('reads the generated file into Skatteverket\'s own space with the headers the RAML lists', function () {
    agdSignedIn();
    Http::fake([AGD_FILING.'/underlag' => Http::response(['inlamningId' => 1234567]), AGD_PERIOD.'/*' => Http::response(['message' => 'Not found'], 404)]);

    agdPanel()
        ->assertSee('Lämna arbetsgivardeklarationen via Skatteverkets API')
        ->assertSee('Beta')
        ->assertSee('uppgiftsskyldigheten är inte fullgjord')
        ->assertSee('Arbetsgivardeklaration på Mina sidor')
        ->assertSee('sparar loggarna i upp till 5 år')
        ->assertSee('tas den förra bort först när den nya är kontrollerad och sparad')
        ->set('figuresReviewed', true)->call('upload', $this->submission->id)
        ->assertDispatched('toast', message: 'Filen är skickad till Skatteverket, som kontrollerar den nu. Hämta kontrollresultatet om en stund. Ingenting är sparat i eget utrymme förrän kontrollen är klar.')
        ->assertSee('Skatteverket kontrollerar filen');

    $xml = (string) Storage::disk('local')->get($this->submission->xml_path);

    // The audit notice is as prominent as the text that loading is not filing (Allmänna villkor 8 a d).
    expect(agdPanel()->html())->not->toContain('text-xs text-gray-500">Skatteverket loggar alla anrop');

    Http::assertSent(fn (Request $request): bool => $request->url() === AGD_FILING.'/underlag'
        && $request->method() === 'POST'
        && $request->body() === $xml
        && $request->hasHeader('Content-Type', 'application/xml')
        && $request->hasHeader('Authorization', 'Bearer person-token')
        && $request->hasHeader('client_id', 'gw-id') && $request->hasHeader('client_secret', 'gw-secret')
        && strlen($request->header('SKV-client_correlationid')[0] ?? '') === 36
        && ! $request->hasHeader('skv_client_correlation_id'));

    $upload = AgiUpload::query()->sole();
    expect($upload->status)->toBe(AgiUpload::PROCESSING)
        ->and($upload->upload_id)->toBe(1234567)
        ->and($upload->agi_submission_id)->toBe($this->submission->id)
        ->and($upload->uploaded_by)->toBe($this->owner->id)
        ->and(AuditLog::query()->where('action', 'payroll.agi_skatteverket_uploaded')->sole()->payload)
        ->toEqual(['period' => '2026-04', 'sequence' => 1, 'upload_id' => 1234567, 'period_state' => null, 'sha256' => $this->submission->sha256]);
});

it('fetches the check result and keeps counts only, never who uploaded or the contacts', function () {
    agdSignedIn();
    $upload = agdUpload(AgiUpload::PROCESSING);
    Http::fake([AGD_FILING.'/underlag/1100130/kontrollresultat' => Http::sequence()->push(['status' => 'PROCESSING', 'inlamnad' => '2026-05-04T09:59:00+0200', 'antalUppgifter' => 2, 'kontrolleradeUppgifter' => 0])->push(agdResultSuccess())]);

    agdPanel()->call('fetchResult', $upload->id)->assertDispatched('toast', message: 'Skatteverket kontrollerar fortfarande filen. Försök igen om en stund.');
    expect($upload->fresh()->status)->toBe(AgiUpload::PROCESSING);

    agdPanel()->call('fetchResult', $upload->id)
        ->assertDispatched('toast', message: 'Skatteverkets kontroll är klar utan stoppande fel. Underlaget är sparat i företagets eget utrymme hos Skatteverket.')
        ->assertSee('Sparad i eget utrymme hos Skatteverket')
        ->assertSee('Skapa granskningslänk')
        ->assertDontSee('Ville Vessla')
        ->assertDontSee('197209223275');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && ! $request->hasHeader('Content-Type') && strlen($request->header('SKV-client_correlationid')[0] ?? '') === 36);

    $stored = json_encode(AgiUpload::query()->sole()->getAttributes());
    expect($upload->fresh()->status)->toBe(AgiUpload::SAVED)
        ->and($upload->fresh()->individual_count)->toBe(1)
        ->and($upload->fresh()->error_count)->toBe(0)
        ->and($stored)->not->toContain('197209223275')->not->toContain('Vessla')->not->toContain('sonjasfrohandel');
});

it('shows Skatteverket\'s own error texts, stores none of them, and saves anyway on request', function () {
    agdSignedIn();
    $upload = agdUpload(AgiUpload::PROCESSING);
    Http::fake([
        AGD_FILING.'/underlag/1100130/kontrollresultat' => Http::response(agdResultFailed()),
        AGD_FILING.'/underlag/1100130/spara' => Http::response(['bekraftelse' => 'Inlämning [1100130] sparad']),
    ]);

    agdPanel()->call('fetchResult', $upload->id)
        ->assertDispatched('toast', message: 'Skatteverkets kontroll hittade fel, så underlaget är inte sparat. Välj om det ska sparas ändå eller tas bort.')
        ->assertSee('Stoppande fel')
        ->assertSee('Det får bara finnas ett skattefält på respektive individuppgift.')
        ->assertSee('Rimlighetsfel')
        ->assertSee('Specifikationsnummer 1')
        ->assertSee('Väljer du inget tar Skatteverket bort det efter 24 timmar')
        ->assertSee('Spara ändå i eget utrymme')
        ->assertDontSee('198508812396')
        ->call('saveAnyway', $upload->id)
        ->assertDispatched('toast', message: 'Underlaget är sparat i eget utrymme hos Skatteverket, med fel som måste rättas på Mina sidor innan deklarationen kan skickas in.');

    Http::assertSent(fn (Request $request): bool => $request->url() === AGD_FILING.'/underlag/1100130/spara' && $request->method() === 'POST' && $request->hasHeader('Content-Type', 'application/json'));

    expect($upload->fresh()->status)->toBe(AgiUpload::SAVED_WITH_ERRORS)
        ->and($upload->fresh()->error_count)->toBe(1)
        ->and($upload->fresh()->warning_count)->toBe(1)
        ->and(json_encode($upload->fresh()->getAttributes()))->not->toContain('S_175')->not->toContain('198508812396');

    // Saving does not change Skatteverket's check result; the row stays saved.
    $this->uploads->result($this->owner, $upload->fresh());
    expect($upload->fresh()->status)->toBe(AgiUpload::SAVED_WITH_ERRORS);
});

it('says plainly when Skatteverket rejected the file and saved nothing', function () {
    agdSignedIn();
    $upload = agdUpload(AgiUpload::PROCESSING);
    Http::fake([AGD_FILING.'/underlag/*' => Http::response([
        'status' => 'DONE_REJECTED', 'inlamnad' => '2022-01-11T13:49:44+0100', 'antalUppgifter' => 0, 'kontrolleradeUppgifter' => 0,
        'kontrollrapport' => ['filstorlek' => 3414, 'filnamn' => 'tmp', 'inkanal' => 'MASK', 'inlamnad' => '2022-01-11T13:49:44+0100', 'bearbetningsfel' => [],
            'valideringsfel' => [['felmeddelande' => "Error at element [AgRegistreradId] at row [38] column [63]: cvc-complex-type.2.2: Element 'agd:AgRegistreradId' must have no element [children], and the value must be valid."]],
            'tekniskKontakt' => [], 'inlamnadAv' => '197209223275', 'redovisningsperioder' => [], 'status' => 'FAILED', 'totaltAntalHU' => 0, 'totaltAntalIU' => 0, 'antalFel' => 4, 'antalVarningar' => 0],
    ])]);

    agdPanel()->call('fetchResult', $upload->id)
        ->assertDispatched('toast', message: 'Skatteverket avvisade filen. Ingenting är sparat i eget utrymme. Felen står nedan.')
        ->assertSee('Avvisad av Skatteverket, inte sparad')
        ->assertSee('cvc-complex-type.2.2')
        ->assertDontSee('Skapa granskningslänk')
        ->assertDontSee('Spara ändå i eget utrymme');

    expect($upload->fresh()->status)->toBe(AgiUpload::REJECTED);
});

it('creates the review link without locking the period and shows only a link to Skatteverket', function () {
    agdSignedIn();
    $upload = agdUpload(AgiUpload::SAVED);
    Http::fake([AGD_FILING.'/arbetsgivare/*' => Http::sequence()
        ->push(['link' => AGD_REVIEW_LINK, 'tillstand' => 'UNLOCKED', 'meddelande' => 'Granskningsunderlag tillgängligt via länk, redovisningsperiod ej låst'])
        ->push(['link' => 'https://skatteverket.se.evil.example/x', 'tillstand' => 'UNLOCKED', 'meddelande' => 'x'])]);

    agdPanel()->call('review', $upload->id)
        ->assertDispatched('toast', message: 'Granskningslänken är klar. Öppna den för att granska och skicka in deklarationen på Mina sidor.')
        ->assertSee('Granska och skicka in på Mina sidor')
        ->assertSee(AGD_REVIEW_LINK)
        ->assertSee('Först då är den lämnad');

    Http::assertSent(fn (Request $request): bool => $request->url() === AGD_FILING.'/arbetsgivare/165563343689/redovisningsperioder/202604/skapaGranskningsunderlag'
        && $request->method() === 'POST' && $request->hasHeader('Content-Type', 'application/json'));

    expect($upload->fresh()->review_state)->toBe('UNLOCKED')
        ->and(AuditLog::query()->where('action', 'payroll.agi_skatteverket_review_link')->sole()->payload)->toEqual(['period' => '2026-04', 'upload_id' => 1100130, 'state' => 'UNLOCKED', 'ready' => true]);

    $this->uploads->review($this->owner, $upload->fresh());
    expect($upload->fresh()->review_link)->toBeNull();
});

it('explains each state Skatteverket answers 409 with', function (array $body, string $toast, ?string $see) {
    agdSignedIn();
    $upload = agdUpload(AgiUpload::SAVED);
    Http::fake([AGD_FILING.'/arbetsgivare/*' => Http::response($body, 409)]);

    $panel = agdPanel()->call('review', $upload->id)->assertDispatched('toast', message: $toast);

    if ($see !== null) {
        $panel->assertSee($see);
    } else {
        $panel->assertDontSee('Granska och skicka in på Mina sidor');
    }

    expect($upload->fresh()->review_state)->toBe($body['tillstand'] === 'INCORRRECT_DATA' ? 'INCORRECT_DATA' : trim($body['tillstand']));
})->with([
    'incorrect data' => [['link' => 'https://sso.skatteverket.se/ms/ms_web/page.do?demo=true&link=signera-ag&huvudman=165563343689&redovisningsperiod=202604', 'tillstand' => 'INCORRECT_DATA', 'meddelande' => 'Felaktiga underlag finns, felrapport tillgänglig via länk'], 'Det finns felaktiga underlag i perioden. Öppna Skatteverkets felrapport och rätta felen.', 'Öppna Skatteverkets felrapport'],
    'misspelt' => [['link' => '', 'tillstand' => 'INCORRRECT_DATA', 'meddelande' => 'x'], 'Det finns felaktiga underlag i perioden. Öppna Skatteverkets felrapport och rätta felen.', null],
    'receiving' => [['link' => '', 'tillstand' => 'RECEIVING', 'meddelande' => 'Inläsning av underlag pågår'], 'Skatteverket läser fortfarande in underlag för perioden. Försök igen om en stund.', null],
    'calculating' => [['link' => '', 'tillstand' => ' CALCULATING', 'meddelande' => 'Beräkning pågår'], 'Skatteverket beräknar perioden. Försök igen om en stund.', null],
    'signing' => [['link' => '', 'tillstand' => 'SIGNING', 'meddelande' => 'Signering av redovisningsperiod pågår'], 'Deklarationen för perioden håller på att skrivas under hos Skatteverket.', null],
    'signed' => [['link' => '', 'tillstand' => 'SIGNED', 'meddelande' => 'Redovisningsperiod är signerad'], 'Deklarationen för perioden är redan underskriven och inskickad. Hämta status från Skatteverket.', null],
]);

it('removes an earlier unsigned upload only once the new file is saved, and keeps it when the new one is refused', function () {
    agdSignedIn();
    $saved = agdUpload(AgiUpload::SAVED, 3423747, ['review_link' => AGD_REVIEW_LINK, 'review_state' => 'UNLOCKED']);
    $failed = agdUpload(AgiUpload::FAILED, 7300034);
    Http::fake([
        AGD_PERIOD.'/*' => Http::response(agdGrunddata()),
        AGD_FILING.'/arbetsgivare/165563343689/redovisningsperioder/202604/inlamningar/3423747' => Http::response(['bekraftelse' => 'Inlämning 3423747 borttagen för 165563343689 i redovisningsperiod 202604']),
        AGD_FILING.'/underlag/7300034' => Http::response(['kod' => 32, 'meddelandeTillAnvandare' => 'Inlämning saknas', 'meddelandeTillUtvecklare' => 'Resurs saknas'], 404),
        AGD_FILING.'/underlag/1234568/kontrollresultat' => Http::sequence()->push(['status' => 'DONE_REJECTED', 'inlamnad' => 'x', 'antalUppgifter' => 0, 'kontrolleradeUppgifter' => 0])->push(agdResultSuccess()),
        AGD_FILING.'/underlag' => Http::response(['inlamningId' => 1234568]),
    ]);

    $new = $this->uploads->upload($this->owner, $this->submission, true);
    expect(AuditLog::query()->where('action', 'figures.reviewed')->sole()->payload)->toBe(['filing' => 'agi_upload']);

    // Nothing earlier is touched by the upload itself.
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    expect($saved->fresh()->status)->toBe(AgiUpload::SAVED);

    // A refused new file leaves the earlier draft where it is.
    $this->uploads->result($this->owner, $new);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    expect($saved->fresh()->status)->toBe(AgiUpload::SAVED);

    $new->forceFill(['status' => AgiUpload::PROCESSING])->save();
    agdPanel()->call('fetchResult', $new->id)
        ->assertDispatched('toast', message: 'Den förra filen som du läste in för månaden är borttagen ur eget utrymme, så att bara den nya finns kvar.');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/inlamningar/3423747'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === AGD_FILING.'/underlag/7300034');
    expect($saved->fresh()->status)->toBe(AgiUpload::REMOVED)
        ->and($saved->fresh()->review_link)->toBeNull()
        ->and($failed->fresh()->status)->toBe(AgiUpload::REMOVED)
        ->and($new->fresh()->status)->toBe(AgiUpload::SAVED);
});

it('never removes the upload behind a signed declaration, also when its receipt was never fetched', function (array $grunddata) {
    agdSignedIn();
    $filed = agdUpload(AgiUpload::SAVED, 3423747);
    Http::fake([
        AGD_PERIOD.'/*' => Http::response($grunddata),
        AGD_FILING.'/underlag/1234569/kontrollresultat' => Http::response(agdResultSuccess()),
        AGD_FILING.'/underlag' => Http::response(['inlamningId' => 1234569]),
    ]);

    $new = $this->uploads->upload($this->owner, $this->submission, true);
    agdPanel()->call('fetchResult', $new->id)
        ->assertDispatched('toast', message: 'Den förra filen för månaden finns kvar i eget utrymme, eftersom deklarationen är underskriven eller låst hos Skatteverket. Den nya filen rättar den när den skickas in på Mina sidor.');

    expect(fn () => $this->uploads->remove($this->owner, $filed))->toThrow(ValidationException::class, 'Ingenting har tagits bort');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    expect($filed->fresh()->status)->toBe(AgiUpload::SAVED);
})->with([
    'signed' => [agdGrunddata('SIGNED', signed: true)],
    'correction started' => [agdGrunddata('CALCULATED', signed: true, correcting: true)],
]);

it('refuses to read in a file while the period is locked or being signed', function (string $state, string $message) {
    agdSignedIn();
    Http::fake([AGD_PERIOD.'/*' => Http::response(agdGrunddata($state)), AGD_FILING.'/*' => Http::response(['inlamningId' => 1])]);

    expect(fn () => $this->uploads->upload($this->owner, $this->submission, true))->toThrow(ValidationException::class, $message);
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), AGD_FILING));
    expect(AgiUpload::query()->count())->toBe(0);
})->with([
    'locked for signing' => ['LOCKED_FOR_SIGNING', 'är låst hos Skatteverket'],
    'locked' => ['LOCKED', 'är låst hos Skatteverket'],
    'signing' => ['SIGNING', 'håller på att skrivas under'],
]);

it('settles the person\'s own upload still being checked before a new one, and lets another person upload', function () {
    agdSignedIn();
    $mine = agdUpload(AgiUpload::PROCESSING, 7300034, ['uploaded_at' => now()->subDays(2)]);
    $colleague = Bookkeeping::member($this->company, 'accountant');
    $theirs = agdUpload(AgiUpload::PROCESSING, 5550001, ['uploaded_by' => $colleague->id]);
    Http::fake([
        AGD_PERIOD.'/*' => Http::response(agdGrunddata()),
        AGD_FILING.'/underlag/7300034/kontrollresultat' => Http::response(['kod' => 32, 'meddelandeTillAnvandare' => 'Inlämning saknas'], 404),
        AGD_FILING.'/underlag' => Http::response(['inlamningId' => 1234570]),
    ]);

    $new = $this->uploads->upload($this->owner, $this->submission, true);

    // Only the person's own row is asked about; the colleague's never blocks and is never called.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '5550001'));
    expect($mine->fresh()->status)->toBe(AgiUpload::REMOVED)
        ->and($theirs->fresh()->status)->toBe(AgiUpload::PROCESSING)
        ->and($new->upload_id)->toBe(1234570);

    Http::fake([AGD_PERIOD.'/*' => Http::response(agdGrunddata()), AGD_FILING.'/underlag/1234570/kontrollresultat' => Http::response(['status' => 'PROCESSING', 'inlamnad' => 'x', 'antalUppgifter' => 1, 'kontrolleradeUppgifter' => 0])]);
    expect(fn () => $this->uploads->upload($this->owner, $this->submission, true))->toThrow(ValidationException::class, 'kontrollerar fortfarande den förra filen');
});

it('lets only the uploader act on an upload, and shows who sent it', function () {
    $colleague = Bookkeeping::member($this->company, 'accountant');
    $colleague->forceFill(['name' => 'Kollegan Testsson'])->save();
    agdSignedIn();
    $theirs = agdUpload(AgiUpload::FAILED, 5550001, ['uploaded_by' => $colleague->id]);
    Http::fake();

    foreach (['result', 'saveAnyway', 'remove', 'review'] as $method) {
        expect(fn () => $this->uploads->{$method}($this->owner, $theirs))->toThrow(ValidationException::class, 'Bara den som skickade filen');
    }

    agdPanel()
        ->assertSee('av Kollegan Testsson')
        ->assertSee('Bara den som skickade filen kan hämta kontrollresultatet')
        ->assertDontSee('Hämta kontrollresultat</button>', escape: false)
        ->assertDontSee('Spara ändå i eget utrymme');

    Http::assertNothingSent();
});

it('marks an upload Skatteverket no longer has as removed', function () {
    agdSignedIn();
    $upload = agdUpload(AgiUpload::PROCESSING);
    Http::fake([AGD_FILING.'/underlag/1100130/kontrollresultat' => Http::response(['kod' => 32, 'meddelandeTillAnvandare' => 'Inlämning saknas', 'meddelandeTillUtvecklare' => 'Resurs saknas'], 404)]);

    agdPanel()->call('fetchResult', $upload->id)
        ->assertDispatched('toast', message: 'Skatteverket har inte längre inlämningen. Den kan ha tagits bort eller rensats efter 24 timmar. Den är markerad som borttagen i NONOX.');

    expect($upload->fresh()->status)->toBe(AgiUpload::REMOVED);
});

it('says that nothing was saved, and why, when the upload is refused', function (int $status, array $body, string $toast) {
    agdSignedIn();
    Http::fake([AGD_PERIOD.'/*' => Http::response(['message' => 'Not found'], 404), AGD_FILING.'/underlag' => Http::response($body, $status)]);

    agdPanel()->set('figuresReviewed', true)->call('upload', $this->submission->id)->assertDispatched('toast', message: $toast);
    expect(AgiUpload::query()->count())->toBe(0);
})->with([
    'too large' => [413, ['kod' => 27, 'meddelandeTillAnvandare' => 'XML-meddelandet är för stort'], 'Ingenting har sparats i företagets eget utrymme hos Skatteverket. XML-meddelandet är för stort Filen är för stor för Skatteverket att ta emot i ett anrop.'],
    'not xml' => [415, ['kod' => 30, 'meddelandeTillAnvandare' => 'XML-meddelandet är ogiltigt'], 'Ingenting har sparats i företagets eget utrymme hos Skatteverket. XML-meddelandet är ogiltigt Skatteverket kunde inte läsa filen som XML. Skapa filen på nytt i NONOX.'],
    'locked' => [400, ['kod' => 33, 'meddelandeTillAnvandare' => 'Perioden är låst - begäran kan ej utföras'], 'Ingenting har sparats i företagets eget utrymme hos Skatteverket. Perioden är låst - begäran kan ej utföras Perioden är låst hos Skatteverket, så inga underlag kan läsas in eller ändras. Lås upp perioden på Mina sidor först.'],
    'too many calls' => [429, ['message' => 'Too many requests'], 'Ingenting har sparats i företagets eget utrymme hos Skatteverket. Skatteverket tar emot för många anrop just nu. Försök igen om en minut. Skatteverket tar emot för många anrop just nu. Vänta en minut och försök igen.'],
    'technical' => [500, ['kod' => 40, 'meddelandeTillAnvandare' => 'Tekniskt fel vid inläsning av deklarationsunderlag'], 'Ingenting har sparats i företagets eget utrymme hos Skatteverket. Tekniskt fel vid inläsning av deklarationsunderlag Skatteverket kunde inte tolka anropet från NONOX. Försök igen; händer det igen, kontakta NONOX.'],
]);

it('says the file may have arrived when Skatteverket does not answer the upload in time', function (Closure $answer) {
    agdSignedIn();
    Http::fake([AGD_PERIOD.'/*' => Http::response(['message' => 'Not found'], 404), AGD_FILING.'/underlag' => $answer]);

    agdPanel()->set('figuresReviewed', true)->call('upload', $this->submission->id)
        ->assertDispatched('toast', message: 'Skatteverket svarade inte i tid, så NONOX vet inte om filen kom fram. Den kan ha tagits emot ändå. Titta under Arbetsgivardeklaration på Mina sidor, eller vänta en stund och hämta status från Skatteverket, innan du skickar filen igen.')
        ->assertNotDispatched('toast', message: 'Skatteverket svarar inte just nu. Försök igen om en stund.');
})->with([
    'timeout' => [fn () => fn () => throw new ConnectionException('timed out')],
    'gateway timeout' => [fn () => fn () => Http::response(['message' => 'Timeout'], 504)],
]);

it('explains format errors from Skatteverket\'s schema check in Swedish', function () {
    agdSignedIn();
    $upload = agdUpload(AgiUpload::PROCESSING);
    Http::fake([AGD_FILING.'/underlag/*' => Http::response([
        'status' => 'DONE_REJECTED', 'inlamnad' => 'x', 'antalUppgifter' => 0, 'kontrolleradeUppgifter' => 0,
        'kontrollrapport' => ['valideringsfel' => [['felmeddelande' => "Error at element [AgRegistreradId] at row [38] column [63]: cvc-complex-type.2.2: Element 'agd:AgRegistreradId' must have no element [children], and the value must be valid."]], 'bearbetningsfel' => [], 'status' => 'FAILED', 'antalFel' => 1],
    ])]);

    agdPanel()->call('fetchResult', $upload->id)
        ->assertSee('Formatfel')
        ->assertSee('Filen följer inte Skatteverkets format för arbetsgivardeklarationen');
});

it('removes a saved upload on request, also when Skatteverket no longer has it', function () {
    agdSignedIn();
    $upload = agdUpload(AgiUpload::SAVED);
    Http::fake([AGD_PERIOD.'/*' => Http::response(agdGrunddata()), AGD_FILING.'/arbetsgivare/*' => Http::response(['kod' => 31, 'meddelandeTillAnvandare' => 'Det saknas sparade deklarationsunderlag för angiven arbetsgivarregisterad och redovisningsperiod'], 404)]);

    agdPanel()->call('remove', $upload->id)->assertDispatched('toast', message: 'Underlaget är borttaget ur företagets eget utrymme hos Skatteverket.');

    expect($upload->fresh()->status)->toBe(AgiUpload::REMOVED)
        ->and(AuditLog::query()->where('action', 'payroll.agi_skatteverket_removed')->count())->toBe(1);
});

it('reads the period\'s status, events and receipts, and never keeps or shows who signed', function () {
    agdSignedIn();
    $upload = agdUpload(AgiUpload::SAVED);
    Http::fake([
        AGD_PERIOD.'/arbetsgivare/165563343689/redovisningsperioder/202604/grunddata' => Http::response(['grunddata' => [
            'period' => ['period' => '202604', 'andringstid' => '2026-05-04T08:58:20+00:00', 'persorgnr' => '165563343689', 'deklarationsdatum' => '2026-05-12', 'betalningsdatum' => '2026-05-12', 'betalningsanstandsdatum' => '', 'deklarationsanstandsdatum' => '', 'sasongsredovisare' => false],
            'statusEgetUtrymme' => 'SIGNED', 'signerad' => true, 'omprovningIniterad' => false, 'statusUnderlag' => 'OK', 'statusHU' => 'OK', 'antalIUstatusOK' => 1, 'antalIUstatusFel' => 0, 'antalIUstatusVarning' => 0, 'antalIUstatusInfo' => 0,
        ]]),
        AGD_PERIOD.'/arbetsgivare/165563343689/redovisningsperioder/202604/handelser' => Http::response(['handelser' => [
            ['handelsetid' => '2026-05-04T08:20:39+00:00', 'handelsetext' => 'Uppgifter som har lästs in via API (tmp_1) har sparats som utkast', 'handelseUtfordAv' => '199999099999'],
            ['handelsetid' => '2026-05-04T08:50:39+00:00', 'handelsetext' => 'Uppgifter har signerats och skickats in elektroniskt.', 'handelseUtfordAv' => '199999099999'],
        ]]),
        AGD_PERIOD.'/arbetsgivare/165563343689/redovisningsperioder/202604/kvittenser' => Http::response(['kvittenser' => [[
            'arbetsgivare' => '165563343689', 'period' => '202604', 'uuidKvittens' => 'kv-0001', 'signeradAv' => '199999099999', 'signeradTid' => '2026-05-04T10:50:39+02:00',
            'underlag' => ['arbetsgivarregistrerad' => '165563343689', 'redovisningsperiod' => '202604', 'antalIu' => 1, 'antalIuTillagda' => 1, 'antalIuBorttagna' => 0, 'summeringsrapport' => ['antalIU' => 1, 'antalRimlighetsfel' => 0, 'antalSummeringsfel' => 0, 'arbetsgivareKontakter' => [['arbetsgivare' => '165563343689', 'namn' => 'Bror Redig', 'epost' => 'bror.redig@example.se']], 'slutsummor' => [], 'summeringsFel' => [], 'rimlighetsFel' => []]],
        ]]]),
    ]);

    agdPanel()->call('refreshStatus')
        ->assertDispatched('toast', message: 'Status för 2026-04 är hämtad från Skatteverket.')
        ->assertSee('Deklarationen är underskriven och inskickad till Skatteverket')
        ->assertSee('Sista dag att deklarera 2026-05-12.')
        ->assertSee('Kvittens kv-0001')
        ->assertSee('Uppgifter har signerats och skickats in elektroniskt.')
        ->assertSee('Markera filen som inlämnad i listan ovan')
        ->assertDontSee('199999099999')
        ->assertDontSee('Bror Redig');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), AGD_PERIOD)
        && strlen($request->header('skv_client_correlation_id')[0] ?? '') === 36
        && ! $request->hasHeader('SKV-client_correlationid'));

    $stored = $upload->fresh();
    expect($stored->period_state)->toBe('SIGNED')
        ->and($stored->period_signed)->toBeTrue()
        ->and($stored->receipt_count)->toBe(1)
        ->and($stored->latest_receipt_id)->toBe('kv-0001')
        ->and($stored->latest_signed_at?->toIso8601String())->toBe('2026-05-04T10:50:39+02:00')
        ->and(json_encode($stored->getAttributes()))->not->toContain('199999099999')->not->toContain('Bror');
});

it('treats a period Skatteverket has nothing for as empty', function () {
    agdSignedIn();
    Http::fake([AGD_PERIOD.'/*' => Http::response(['message' => 'Not found'], 404)]);

    agdPanel()->call('refreshStatus')
        ->assertDispatched('toast', message: 'Skatteverket har inget för 2026-04 än.')
        ->assertSee('Skatteverket har ingen arbetsgivardeklaration och inget underlag för månaden.');
});

it('shows Skatteverket\'s text when the role is refused, and ends the sign-in on 401 or a missing scope', function () {
    agdSignedIn();
    Http::fake([AGD_PERIOD.'/*' => Http::sequence()
        ->push(['message' => 'Not found'], 404)
        ->push(['message' => 'Not found'], 404)
        ->push(['error' => 'invalid_scope', 'description' => 'The required scope agdredovisningperiod has been requested for that access token.'], 403), AGD_FILING.'/underlag' => Http::sequence()
        ->push(['kod' => 403, 'meddelandeTillAnvandare' => 'Behörighet saknas för aktör att företräda arbetsgivaren.'], 403)
        ->push(['error' => 'Token has been revoked.'], 401)]);

    agdPanel()->set('figuresReviewed', true)->call('upload', $this->submission->id)->assertDispatched('toast', message: 'Ingenting har sparats i företagets eget utrymme hos Skatteverket. Behörighet saknas för aktör att företräda arbetsgivaren.');
    expect(SkatteverketToken::query()->count())->toBe(1)->and(AgiUpload::query()->count())->toBe(0);

    agdPanel()->set('figuresReviewed', true)->call('upload', $this->submission->id)->assertDispatched('toast', message: 'Inloggningen hos Skatteverket har gått ut. Logga in hos Skatteverket igen.');
    expect(SkatteverketToken::query()->count())->toBe(0);

    agdSignedIn();

    agdPanel()->call('refreshStatus')->assertDispatched('toast', message: 'Inloggningen hos Skatteverket gäller inte den här tjänsten. Logga in hos Skatteverket igen.');
    expect(SkatteverketToken::query()->count())->toBe(0);
});

it('asks a person signed in for the VAT return only to sign in again', function () {
    agdSignedIn(scope: 'momsdeklaration');
    Http::fake();

    expect(SkatteverketAuth::isSignedIn($this->owner))->toBeTrue()
        ->and(SkatteverketAuth::isSignedIn($this->owner, 'agd'))->toBeFalse();

    agdPanel()
        ->assertSee('Din inloggning hos Skatteverket gäller bara momsdeklarationen.')
        ->assertSee('Logga in hos Skatteverket med e-legitimation')
        ->assertDontSee('Skicka filen till Skatteverket')
        ->set('figuresReviewed', true)->call('upload', $this->submission->id)
        ->assertDispatched('toast', message: 'Din inloggning hos Skatteverket gäller inte arbetsgivardeklarationen. Logga in hos Skatteverket igen.');

    Http::assertNothingSent();
    expect(SkatteverketToken::query()->count())->toBe(1);
});

it('asks only for the scopes of the service the sign-in starts from, plus those of a usable sign-in', function () {
    $location = fn (string $service): string => (string) $this->get(route('skatteverket.signIn', ['tillbaka' => '/lon/arbetsgivardeklaration', 'tjanst' => $service]))->headers->get('Location');

    expect($location('arbetsgivardeklaration'))->toEndWith('scope=agd%20agdredovisningperiod')
        ->and($location('moms'))->toEndWith('scope=momsdeklaration')
        ->and($location('okand'))->toEndWith('scope=momsdeklaration');

    agdSignedIn(scope: 'momsdeklaration');
    expect($location('arbetsgivardeklaration'))->toEndWith('scope=momsdeklaration%20agd%20agdredovisningperiod');

    // The panel starts the sign-in for the employer declaration.
    SkatteverketToken::query()->delete();
    agdPanel()->assertSee('tjanst=arbetsgivardeklaration', escape: false);
});

it('stores the asked-for scopes when the token answer names none, and keeps them on a refresh', function () {
    $flow = ['state' => 'right', 'user_id' => $this->owner->id, 'back' => '/lon/arbetsgivardeklaration', 'scopes' => ['agd', 'agdredovisningperiod'], 'at' => now()->getTimestamp()];
    Http::fake(['https://peroauth2.test.skatteverket.se/oauth2/v1/per/token' => Http::sequence()
        ->push(['access_token' => 'first', 'expires_in' => 3600, 'refresh_token' => 'refresh'])
        ->push(['access_token' => 'second', 'expires_in' => 3600, 'refresh_token' => 'refresh-2'])]);

    $this->withSession([SkatteverketAuthController::SESSION => $flow])
        ->get(route('skatteverket.callback', ['code' => 'abc', 'state' => 'right']));
    expect(SkatteverketToken::query()->sole()->scope)->toBe('agd agdredovisningperiod');

    SkatteverketToken::query()->update(['expires_at' => now()->subMinute()]);
    expect(SkatteverketAuth::accessToken($this->owner, 'agd'))->toBe('second')
        ->and(SkatteverketToken::query()->sole()->scope)->toBe('agd agdredovisningperiod');
});

it('is offered only to pilot users, with the keys set and the payroll module on', function () {
    agdSignedIn();
    Http::fake();
    config(['services.skatteverket.pilot_users' => 'pilot@example.se']);

    Livewire::test(AgiPage::class)->assertDontSee('Lämna arbetsgivardeklarationen via Skatteverkets API');
    agdPanel()->assertForbidden();
    expect(fn () => $this->uploads->upload($this->owner, $this->submission, true))->toThrow(ValidationException::class, 'inte konfigurerad');

    config(['services.skatteverket.pilot_users' => '', 'services.skatteverket.api_client_secret' => '']);
    Livewire::test(AgiPage::class)->assertDontSee('Lämna arbetsgivardeklarationen via Skatteverkets API');

    config(['services.skatteverket.api_client_secret' => 'gw-secret']);
    Livewire::test(AgiPage::class)->assertSee('Lämna arbetsgivardeklarationen via Skatteverkets API');

    Http::assertNothingSent();
});

it('lets only those who may submit the declaration send, and the archive role read the status', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $auditor = Bookkeeping::member($this->company, 'auditor');
    agdSignedIn($auditor);
    $upload = agdUpload(AgiUpload::SAVED);
    Http::fake([AGD_PERIOD.'/*' => Http::response(['message' => 'Not found'], 404)]);

    expect(fn () => $this->uploads->upload($bookkeeper, $this->submission, true))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->uploads->review($auditor, $upload))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->uploads->remove($auditor, $upload))->toThrow(AuthorizationException::class)
        ->and($this->uploads->periodStatus($auditor, $this->company, '2026-04')['period'])->toBeNull();

    expect(AuditLog::query()->where('action', 'payroll.agi_skatteverket_status')->count())->toBe(0)
        ->and($upload->fresh()->period_fetched_at)->toBeNull();

    $this->actingAs($auditor);
    agdPanel()->assertOk()->assertDontSee('Skicka filen till Skatteverket')->assertDontSee('Hämta kontrollresultat');

    $this->actingAs($bookkeeper);
    agdPanel()->assertForbidden();
});

it('never acts on another company\'s upload', function () {
    agdSignedIn();
    Http::fake();
    [, $other] = Bookkeeping::company();
    $foreign = CurrentCompany::run($other, fn () => AgiUpload::query()->forceCreate([
        'company_id' => $other->id,
        'agi_submission_id' => AgiSubmission::query()->forceCreate(['company_id' => $other->id, 'period' => '2026-04', 'sequence' => 1, 'xml_path' => 'agi/x.xml', 'sha256' => str_repeat('b', 64), 'tax_ore' => 0, 'contributions_ore' => 0, 'individuals' => [], 'generated_by' => $this->owner->id])->id,
        'period' => '2026-04', 'upload_id' => 999, 'sha256' => str_repeat('b', 64), 'status' => AgiUpload::FAILED, 'uploaded_at' => now(),
    ]));

    foreach (['fetchResult', 'saveAnyway', 'remove', 'review'] as $method) {
        agdPanel()->call($method, $foreign->id)->assertNotFound();
    }

    agdPanel()->set('figuresReviewed', true)->call('upload', $foreign->agi_submission_id)->assertNotFound();
    Http::assertNothingSent();
    expect(CompanyScope::bypass(fn () => $foreign->fresh()->status))->toBe(AgiUpload::FAILED);
});

it('runs a fixed number of queries however many uploads the month has', function () {
    agdSignedIn();
    agdUpload(AgiUpload::REMOVED, 1);

    DB::enableQueryLog();
    agdPanel();
    $few = count(DB::getQueryLog());

    foreach (range(2, 9) as $id) {
        agdUpload(AgiUpload::REMOVED, $id);
    }

    DB::flushQueryLog();
    agdPanel();
    expect(count(DB::getQueryLog()))->toBe($few);
});

it('reduces the check result to texts, statuses and counts', function () {
    $result = AgdResult::from(agdResultFailed());

    expect($result['status'])->toBe('DONE_FAILED')
        ->and($result['errors'])->toBe(1)
        ->and($result['messages'])->toHaveCount(2)
        ->and($result['messages'][1])->toBe(['kind' => 'ARENDE', 'text' => 'Beloppet i fältet "Avdragen preliminär skatt" är högre än 10 000 000 kronor. (R_006)', 'specification' => 1])
        ->and(json_encode($result))->not->toContain('198508812396')->not->toContain('197209223275')->not->toContain('Vessla');
});

it('does not let the browser change what Skatteverket answered', function (string $property, mixed $value) {
    agdSignedIn();

    expect(fn () => agdPanel()->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'result' => ['result', ['status' => 'DONE_SUCCESS', 'messages' => []]],
    'result upload' => ['resultUpload', 1],
    'status' => ['status', ['period' => null, 'events' => [], 'receipts' => []]],
]);

it('refuses the AGI upload without "Jag har granskat siffrorna" and records the confirmation when given', function () {
    Http::fake();

    expect(fn () => $this->uploads->upload($this->owner, $this->submission))->toThrow(ValidationException::class, 'granskat siffrorna');
    Http::assertNothingSent();
    expect(AuditLog::query()->where('action', 'figures.reviewed')->count())->toBe(0);
});
