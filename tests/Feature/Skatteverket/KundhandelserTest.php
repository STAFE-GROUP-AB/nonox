<?php

declare(strict_types=1);

use App\Actions\SkatteverketEvents\FetchSkatteverketEvents;
use App\Actions\SkatteverketEvents\MarkSkatteverketEvent;
use App\Domain\Company\LegalForm;
use App\Http\Controllers\Skatteverket\SkatteverketAuthController;
use App\Livewire\Guide\SkatteverketEvents;
use App\Livewire\Overview\OverviewPage;
use App\Models\AuditLog;
use App\Models\SkatteverketEvent;
use App\Models\SkatteverketToken;
use App\Support\Skatteverket\Kundhandelser\KundhandelserApi;
use App\Support\Skatteverket\Kundhandelser\KundhandelserErrors;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Kundhändelser v4 (RAML 4.0.5, ADR-054 stage 4): Skatteverket's customer
 * events about the company on Mina datum and Översikt. The RAML's examples
 * are commented out, so the fixture is written to its response schema.
 */

const KHD_API = 'https://api.test.skatteverket.se/foretagochorganisationer/kundhandelser/v4/hamta';

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
    $this->travelTo(CarbonImmutable::parse('2026-02-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['org_number' => '556334-3689', 'legal_form' => LegalForm::Ab])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

function khdFixture(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/skatteverket/kundhandelser/hamtaResponse.json')), true, flags: JSON_THROW_ON_ERROR);
}

function khdSignIn(string $scope = 'momsdeklaration agd agdredovisningperiod ska kundhandelser', ?int $userId = null): void
{
    SkatteverketToken::query()->create([
        'user_id' => $userId ?? test()->owner->id,
        'access_token' => 'person-token',
        'refresh_token' => 'person-refresh',
        'scope' => $scope,
        'expires_at' => now()->addHour(),
        'refresh_expires_at' => now()->addMinutes(65),
    ]);
}

function khdFake(?array $body = null, int $status = 200): void
{
    Http::fake([KHD_API => Http::response($body ?? khdFixture(), $status)]);
}

/** @param  array<string, mixed>  $overrides */
function khdEvent(array $overrides = []): SkatteverketEvent
{
    static $number = 0;
    $number++;

    return SkatteverketEvent::query()->create([
        'event_id' => "skv-made-{$number}",
        'type' => 'SKV.TEST.TY_TEST_V1',
        'producer' => 'skatteverket',
        'occurred_at' => now()->subDays($number),
        'title' => "Händelse {$number}",
        'description' => "Beskrivning {$number}",
        'language' => 'sv',
        'requires_action' => false,
        'case_closed' => false,
        'fetched_by' => test()->owner->id,
        'fetched_at' => now(),
        ...$overrides,
    ]);
}

it('asks for the company\'s events with only the headers and body the RAML lists, and reads its response schema', function () {
    khdFake();

    $events = (new KundhandelserApi('person-token'))->fetch('165563343689');

    expect($events)->toHaveCount(3)
        ->and($events[0])->toBe([
            'id' => 'skv-test-0001',
            'type' => 'SKV.MOMS.TY_KOMPLETTERING_BEGARD_V1',
            'producer' => 'skatteverket.beskattning.moms',
            'occurredAt' => '2026-02-10T09:15:00+01:00',
            'title' => 'Skatteverket behöver en komplettering av momsdeklarationen för december 2025',
            'description' => "Vi behöver fler uppgifter för att kunna handlägga momsdeklarationen.\nSkicka in kompletteringen senast den 3 mars 2026.",
            'language' => 'sv',
            'requiresAction' => true,
            'closed' => false,
            'dueDate' => '2026-03-03',
            'links' => [['url' => 'https://www.skatteverket.se/foretag/etjansterochblanketter', 'text' => 'Komplettera hos Skatteverket', 'skatteverket' => true]],
            'kopplingsId' => 'moms-komplettering-2025-12',
            'skatteslag' => null,
            'kundamne' => ['Moms'],
            'korrespondensReferens' => 'KORR-2026-0042',
            'notice' => 'Du har ett nytt meddelande från Skatteverket.',
        ])
        // Another host, or one that only starts like skatteverket.se, is kept as text, not as a link.
        ->and($events[2]['links'])->toBe([
            ['url' => 'https://example.com/inte-skatteverket', 'text' => null, 'skatteverket' => false],
            ['url' => 'https://skatteverket.se.example.com/falsk', 'text' => 'Falsk länk', 'skatteverket' => false],
        ])
        ->and($events[2]['closed'])->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === KHD_API
        && $request->data() === ['kund' => ['identifierare' => '165563343689', 'typ' => 'Organisationsnummer']]);
    Http::assertNotSent(fn (Request $request): bool => ! $request->hasHeader('Authorization', 'Bearer person-token')
        || ! $request->hasHeader('client_id', 'gw-id') || ! $request->hasHeader('client_secret', 'gw-secret')
        || strlen($request->header('skv_client_correlation_id')[0] ?? '') !== 36
        || $request->hasHeader('SKV-client_correlationid')
        || $request->hasHeader('Accept-Language')
        || ! $request->hasHeader('Content-Type', 'application/json')
        || ! $request->hasHeader('Accept', 'application/json'));
});

it('fetches on the person\'s click, keeps the events in full and never the person who fetched', function () {
    khdSignIn();
    khdFake();

    Livewire::test(SkatteverketEvents::class)
        ->assertSee('Från Skatteverket')
        ->assertSee('Inte hämtat än.')
        ->call('fetch')
        ->assertDispatched('toast', message: 'Kundhändelserna är hämtade från Skatteverket. 3 nya händelser.')
        ->assertSee('Skatteverket väntar på företaget')
        ->assertSee('Skatteverket behöver en komplettering av momsdeklarationen för december 2025')
        ->assertSee('Skicka in kompletteringen senast den 3 mars 2026.')
        ->assertSee('Förfallodag 2026-03-03')
        ->assertSee('Komplettera hos Skatteverket')
        ->assertSee('https://www.skatteverket.se/foretag/etjansterochblanketter', false)
        ->assertSee('Skatteverket ser ärendet som klart.')
        ->assertSee('Ämne: Moms')
        ->assertSee('Referens: KORR-2026-0042')
        ->assertSee('Avisering från Skatteverket: Du har ett nytt meddelande från Skatteverket.')
        ->assertSee('https://example.com/inte-skatteverket')
        ->assertSee('Falsk länk – https://skatteverket.se.example.com/falsk')
        ->assertDontSee('href="https://example.com', false)
        ->assertDontSee('href="https://skatteverket.se.example.com', false);

    expect(SkatteverketEvent::query()->count())->toBe(3)
        ->and(SkatteverketEvent::query()->awaitingCompany()->pluck('event_id')->all())->toBe(['skv-test-0001']);

    // The person's personnummer (metadata.anvandare), the roles, the case reference and the notice text are not kept.
    $stored = json_encode(DB::table('skatteverket_events')->get()->all(), JSON_UNESCAPED_UNICODE);
    expect($stored)->not->toContain('191010101234')
        ->and($stored)->not->toContain('Firmatecknare')
        ->and($stored)->not->toContain('MOMS-2025-12-0001')
        ->and($stored)->not->toContain('Aktiebolag')
        ->and(SkatteverketEvent::query()->where('fetched_by', $this->owner->id)->count())->toBe(3);

    $audit = AuditLog::query()->where('action', 'skatteverket.events_fetched')->sole();
    expect($audit->payload)->toEqual(['fetched' => 3, 'new' => 3, 'awaiting' => 1]);
});

it('keeps who marked an event handled when the events are fetched again, and says there is nothing new', function () {
    khdSignIn();
    khdFake();
    app(FetchSkatteverketEvents::class)($this->owner, $this->company);
    $event = SkatteverketEvent::query()->where('event_id', 'skv-test-0001')->sole();
    app(MarkSkatteverketEvent::class)($this->owner, $this->company, $event->id, true);

    Livewire::test(SkatteverketEvents::class)
        ->call('fetch')
        ->assertDispatched('toast', message: 'Kundhändelserna är hämtade från Skatteverket. Inga nya sedan förra gången.')
        ->assertSee('Markerad som hanterad 2026-02-15');

    expect(SkatteverketEvent::query()->count())->toBe(3)
        ->and($event->fresh()->handled_by)->toBe($this->owner->id)
        ->and(SkatteverketEvent::query()->awaitingCompany()->count())->toBe(0);
});

it('counts the events that wait for the company on Översikt until someone marks them handled', function () {
    $event = khdEvent(['requires_action' => true, 'title' => 'Lämna in en komplettering']);
    khdEvent(['requires_action' => false]);

    Livewire::test(OverviewPage::class)
        ->assertSee('1 händelse från Skatteverket väntar på företaget.')
        ->assertSee(route('guide.dates').'#fran-skatteverket', false);

    Livewire::test(SkatteverketEvents::class)
        ->call('markHandled', $event->id)
        ->assertDispatched('toast', message: 'Händelsen är markerad som hanterad.')
        ->assertSee('Markera som inte hanterad');

    Livewire::test(OverviewPage::class)->assertDontSee('väntar på företaget');
    expect(AuditLog::query()->where('action', 'skatteverket.event_handled')->sole()->payload)->toBe([]);

    Livewire::test(SkatteverketEvents::class)
        ->call('markUnhandled', $event->id)
        ->assertDispatched('toast', message: 'Händelsen är markerad som inte hanterad.');
    Livewire::test(OverviewPage::class)->assertSee('1 händelse från Skatteverket väntar på företaget.');
});

it('shows the events on Mina datum, also while Företagsguiden is off', function () {
    khdEvent(['requires_action' => true, 'title' => 'Lämna in en komplettering']);

    $this->get(route('guide.dates'))->assertOk()->assertSee('Från Skatteverket')->assertSee('Lämna in en komplettering');

    $this->company->forceFill(['guide_enabled' => false])->save();
    $this->actingAs($this->owner->fresh())->get(route('guide.dates'))->assertOk()->assertSee('Företagsguiden är avstängd för företaget')->assertSee('Lämna in en komplettering');
});

it('explains a missing representative\'s role, too many calls and an outage, and keeps the sign-in', function (int $status, mixed $body, string $message) {
    khdSignIn();
    Http::fake([KHD_API => Http::response($body, $status)]);

    Livewire::test(SkatteverketEvents::class)->call('fetch')->assertDispatched('toast', message: $message);

    expect(SkatteverketToken::query()->count())->toBe(1)
        ->and(SkatteverketEvent::query()->count())->toBe(0);
})->with([
    '403 ombudsroll' => [403, ['message' => KundhandelserErrors::NO_ROLE], 'Skatteverket säger att du saknar behörighet att ta del av företagets kundhändelser (ombudsbehörighet saknas). Du behöver företräda företaget, till exempel som firmatecknare eller styrelseledamot registrerad hos Bolagsverket, eller vara ombud med behörighet för kundhändelser hos Skatteverket.'],
    '403' => [403, ['message' => 'Forbidden'], 'Skatteverket säger att du saknar behörighet att ta del av företagets kundhändelser.'],
    '429' => [429, ['message' => 'To many requests'], 'Skatteverket tar emot för många anrop just nu. Försök igen om en minut.'],
    '500' => [500, ['message' => 'Internal server error'], 'Skatteverket kunde inte svara just nu. Försök igen om en minut.'],
    '503' => [503, 'Service not available for request uri: /url path', 'Skatteverket kunde inte svara just nu. Försök igen om en minut.'],
    '504' => [504, ['message' => 'Timeout'], 'Skatteverket kunde inte svara just nu. Försök igen om en minut.'],
    '400' => [400, ['message' => 'Bad request'], 'Skatteverket kunde inte visa kundhändelserna (felkod 400).'],
]);

it('ends the sign-in on 401', function () {
    khdSignIn();
    Http::fake([KHD_API => Http::response(['message' => 'Unauthorized'], 401)]);

    Livewire::test(SkatteverketEvents::class)->call('fetch')
        ->assertDispatched('toast', message: 'Inloggningen hos Skatteverket har gått ut. Logga in hos Skatteverket igen.');

    expect(SkatteverketToken::query()->count())->toBe(0);
});

it('asks a sign-in without the scope kundhandelser to sign in again for it, without ending it for the other services', function () {
    khdSignIn('momsdeklaration ska');
    Http::fake();

    Livewire::test(SkatteverketEvents::class)
        ->assertSee('Din inloggning hos Skatteverket gäller inte kundhändelser.')
        ->assertSee('tjanst=kundhandelser', false)
        ->assertDontSee('wire:click="fetch"', false)
        ->call('fetch')
        ->assertDispatched('toast', message: 'Logga in hos Skatteverket för kundhändelser först.');

    expect(SkatteverketToken::query()->count())->toBe(1);
    Http::assertNothingSent();

    $start = $this->get(route('skatteverket.signIn', ['tillbaka' => '/foretagsguiden/mina-datum', 'tjanst' => 'kundhandelser']));
    expect($start->headers->get('Location'))->toEndWith('scope=momsdeklaration%20ska%20kundhandelser')
        ->and(session(SkatteverketAuthController::SESSION)['back'])->toBe('/foretagsguiden/mina-datum');

    SkatteverketToken::query()->delete();
    expect($this->get(route('skatteverket.signIn', ['tjanst' => 'kundhandelser']))->headers->get('Location'))->toEndWith('scope=kundhandelser');
});

it('keeps the sign-in for the other services when Skatteverket refuses the scope kundhandelser', function () {
    khdSignIn();
    Http::fake([KHD_API => Http::response(['error' => 'invalid_scope', 'error_description' => 'The required scopes are not authorized'], 403)]);
    Livewire::test(SkatteverketEvents::class)->call('fetch')
        ->assertDispatched('toast', message: 'Inloggningen hos Skatteverket gäller inte kundhändelser. Logga in hos Skatteverket igen för kundhändelser.');
    expect(SkatteverketToken::query()->count())->toBe(1);
});

it('is offered only to the pilot users, with the keys set', function () {
    khdEvent(['requires_action' => true, 'title' => 'Lämna in en komplettering']);
    Http::fake();
    config(['services.skatteverket.pilot_users' => 'pilot@example.se']);

    Livewire::test(SkatteverketEvents::class)->assertDontSee('Från Skatteverket')->assertDontSee('Lämna in en komplettering');
    Livewire::test(OverviewPage::class)->assertDontSee('väntar på företaget');
    expect(fn () => app(FetchSkatteverketEvents::class)($this->owner, $this->company))->toThrow(AuthorizationException::class);
    Livewire::test(SkatteverketEvents::class)->call('fetch')->assertDispatched('toast', message: 'Du har inte behörighet att göra det.');

    $this->owner->forceFill(['email' => 'pilot@example.se'])->save();
    Livewire::test(SkatteverketEvents::class)->assertSee('Lämna in en komplettering');

    config(['services.skatteverket.api_client_secret' => '']);
    Livewire::test(SkatteverketEvents::class)->assertDontSee('Lämna in en komplettering');
    $this->get(route('skatteverket.signIn', ['tjanst' => 'kundhandelser']))->assertNotFound();
    Http::assertNothingSent();
});

it('shows each person only the events they fetched themselves, and nothing to a role without reports', function () {
    $event = khdEvent(['requires_action' => true, 'title' => 'Lämna in en komplettering']);
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $approver = Bookkeeping::member($this->company, 'approver');
    khdSignIn(userId: $auditor->id);
    khdFake();

    $this->actingAs($auditor);
    Livewire::test(SkatteverketEvents::class)
        ->assertDontSee('Lämna in en komplettering')
        ->assertSee('Händelser hämtas och visas för den som själv har loggat in hos Skatteverket.')
        ->call('markHandled', $event->id)
        ->assertNotFound();
    Livewire::test(OverviewPage::class)->assertDontSee('väntar på företaget');
    expect($event->fresh()->handled_at)->toBeNull();

    // The auditor fetches their own copy, and can mark it.
    $page = Livewire::test(SkatteverketEvents::class)
        ->call('fetch')
        ->assertDispatched('toast', message: 'Kundhändelserna är hämtade från Skatteverket. 3 nya händelser.')
        ->assertSee('Skatteverket behöver en komplettering av momsdeklarationen för december 2025')
        ->assertDontSee('Lämna in en komplettering');
    $own = SkatteverketEvent::query()->fetchedBy($auditor)->where('event_id', 'skv-test-0001')->sole();
    $page->call('markHandled', $own->id)->assertDispatched('toast', message: 'Händelsen är markerad som hanterad.');
    expect(SkatteverketEvent::query()->count())->toBe(4)
        ->and($event->fresh()->handled_at)->toBeNull();

    $this->actingAs($this->owner);
    Livewire::test(OverviewPage::class)->assertSee('1 händelse från Skatteverket väntar på företaget.');

    $this->actingAs($approver);
    Livewire::test(SkatteverketEvents::class)->assertDontSee('Från Skatteverket')->assertDontSee('Lämna in en komplettering');
    Livewire::test(OverviewPage::class)->assertDontSee('väntar på företaget');
    expect(fn () => app(FetchSkatteverketEvents::class)($approver, $this->company))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MarkSkatteverketEvent::class)($approver, $this->company, $event->id, true))->toThrow(AuthorizationException::class);
});

it('keeps one row per person when two people fetch the same event, each with its own handled mark', function () {
    $accountant = Bookkeeping::member($this->company, 'accountant');
    khdSignIn();
    khdSignIn(userId: $accountant->id);
    khdFake();

    app(FetchSkatteverketEvents::class)($this->owner, $this->company);
    app(FetchSkatteverketEvents::class)($accountant, $this->company);
    $mine = SkatteverketEvent::query()->fetchedBy($this->owner)->where('event_id', 'skv-test-0001')->sole();
    app(MarkSkatteverketEvent::class)($this->owner, $this->company, $mine->id, true);

    expect(SkatteverketEvent::query()->where('event_id', 'skv-test-0001')->count())->toBe(2)
        ->and(SkatteverketEvent::query()->fetchedBy($this->owner)->awaitingCompany()->count())->toBe(0)
        ->and(SkatteverketEvent::query()->fetchedBy($accountant)->awaitingCompany()->count())->toBe(1);
});

it('counts an event as waiting only while it is the newest in its chain and the matter is open', function () {
    $asked = khdEvent(['requires_action' => true, 'kopplings_id' => 'alkoholskatt-maj-2026', 'occurred_at' => now()->subDays(10), 'title' => 'Lämna in deklaration för alkoholskatt']);
    $closedItself = khdEvent(['requires_action' => true, 'case_closed' => true, 'title' => 'Klart men med åtgärd']);
    $alone = khdEvent(['requires_action' => true, 'title' => 'Ensam begäran']);

    expect(SkatteverketEvent::query()->awaitingCompany()->pluck('id')->sort()->values()->all())->toBe(collect([$asked->id, $alone->id])->sort()->values()->all());

    // The declaration came in: a later event in the same chain, so the request no longer waits.
    khdEvent(['requires_action' => false, 'kopplings_id' => 'alkoholskatt-maj-2026', 'occurred_at' => now()->subDays(2), 'title' => 'Punktskatt deklaration inlämnad']);
    // Another person's later event does not move this person's chain.
    khdEvent(['requires_action' => false, 'kopplings_id' => 'ensam', 'fetched_by' => Bookkeeping::member($this->company, 'accountant')->id]);

    expect(SkatteverketEvent::query()->awaitingCompany()->pluck('id')->all())->toBe([$alone->id])
        ->and($closedItself->id)->not->toBeIn(SkatteverketEvent::query()->awaitingCompany()->pluck('id')->all());

    Livewire::test(SkatteverketEvents::class)->assertSee('En senare händelse i samma ärende har kommit.');
    Livewire::test(OverviewPage::class)->assertSee('1 händelse från Skatteverket väntar på företaget.');
});

it('drops NUL bytes from Skatteverket\'s texts', function () {
    $body = khdFixture();
    $body['kundhandelser'][0]['rubrik'] = "Rubrik\0 med NUL";
    $body['kundhandelser'][0]['beskrivning'] = "Text\0";
    $body['kundhandelser'][0]['kundhandelseId'] = "skv\0-id";
    khdFake($body);

    $event = (new KundhandelserApi('person-token'))->fetch('165563343689')[0];

    expect($event['title'])->toBe('Rubrik med NUL')
        ->and($event['description'])->toBe('Text')
        ->and($event['id'])->toBe('skv-id');
});

it('fetches only for legal persons, never a sole trader\'s personnummer', function () {
    $this->company->forceFill(['org_number' => '811218-9876', 'legal_form' => LegalForm::Ef])->save();
    khdSignIn();
    Http::fake();

    Livewire::test(SkatteverketEvents::class)
        ->assertSee('Kundhändelser från Skatteverket kan bara hämtas för juridiska personer')
        ->assertDontSee('wire:click="fetch"', false);
    expect(fn () => app(FetchSkatteverketEvents::class)($this->owner, $this->company))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('shows what the terms require', function () {
    Livewire::test(SkatteverketEvents::class)
        ->assertSee('Beta')
        ->assertSee('Testmiljö')
        ->assertSee('aldrig av sig själv')
        ->assertSee('för att få återkoppling om företagets ärenden hos Skatteverket')
        ->assertSee('Händelser hämtas och visas för den som själv har loggat in hos Skatteverket.')
        ->assertSee('tar bort händelserna två år efter händelsen')
        ->assertSee('Skatteverket loggar alla anrop från NONOX (auditloggning) och sparar loggarna i upp till 5 år. Skatteverket använder dem för att förebygga och utreda säkerhetsincidenter.');
});

it('shows Skatteverket\'s text as text, never as markup', function () {
    khdEvent(['title' => '<b>Rubrik</b>', 'description' => '<script>alert(1)</script>']);

    Livewire::test(SkatteverketEvents::class)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('lists the latest events first and the rest behind Visa alla händelser', function () {
    foreach (range(1, SkatteverketEvents::RECENT + 2) as $index) {
        khdEvent(['title' => "Äldre händelse {$index}", 'occurred_at' => now()->subDays(100 + $index)]);
    }

    Livewire::test(SkatteverketEvents::class)
        ->assertSee('Äldre händelse 1')
        ->assertDontSee('Äldre händelse 12')
        ->assertSee('Visa alla händelser')
        ->call('showEverything')
        ->assertSee('Äldre händelse 12')
        ->assertDontSee('Visa alla händelser')
        ->assertSet('showAll', true);

    // Server state: the page cannot set it itself.
    expect(fn () => Livewire::test(SkatteverketEvents::class)->set('showAll', true))->toThrow(Exception::class);
});

it('runs a fixed number of queries whatever the number of events', function () {
    $count = function (int $events): int {
        foreach (range(1, $events) as $index) {
            khdEvent(['requires_action' => $index % 3 === 0, 'handled_at' => $index % 2 === 0 ? now() : null, 'handled_by' => $index % 2 === 0 ? $this->owner->id : null]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(SkatteverketEvents::class)->call('showEverything');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // The first render warms the permission and role look-ups.
    $count(1);
    $few = $count(3);
    $many = $count(30);

    expect($many)->toBe($few);
});

it('limits how often a person fetches the events', function () {
    khdSignIn();
    khdFake();
    RateLimiter::clear("skatteverket-events:{$this->owner->id}");
    $page = Livewire::test(SkatteverketEvents::class);

    foreach (range(1, SkatteverketEvents::FETCHES_PER_MINUTE) as $ignored) {
        $page->call('fetch');
    }

    $page->call('fetch')->assertDispatched('toast', message: 'Du har hämtat kundhändelserna många gånger på kort tid. Vänta en minut och försök igen.');
    Http::assertSentCount(SkatteverketEvents::FETCHES_PER_MINUTE);
});

it('prunes the events two years after them, when Skatteverket also removes them, and those whose fetcher is gone', function () {
    $old = khdEvent(['occurred_at' => now()->subDays(SkatteverketEvent::RETENTION_DAYS + 1)]);
    $kept = khdEvent(['occurred_at' => now()->subDays(SkatteverketEvent::RETENTION_DAYS - 1)]);
    $timeless = khdEvent(['occurred_at' => null, 'fetched_at' => now()->subDays(SkatteverketEvent::RETENTION_DAYS + 1)]);
    $timelessRecent = khdEvent(['occurred_at' => null, 'fetched_at' => now()]);
    $orphan = khdEvent(['fetched_by' => null]);

    $this->artisan('privacy:prune')->expectsOutputToContain('skatteverket_events: 3')->assertSuccessful();

    expect(SkatteverketEvent::query()->pluck('id')->sort()->values()->all())->toBe(collect([$kept->id, $timelessRecent->id])->sort()->values()->all())
        ->and($old->fresh())->toBeNull()
        ->and($timeless->fresh())->toBeNull()
        ->and($orphan->fresh())->toBeNull();
});
