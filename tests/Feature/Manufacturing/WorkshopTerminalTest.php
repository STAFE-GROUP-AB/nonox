<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\SalesDocument;
use App\Modules\Manufacturing\Actions\Production\ProductionOrderLifecycle;
use App\Modules\Manufacturing\Actions\Workshop\RecordTerminalTime;
use App\Modules\Manufacturing\Actions\Workshop\WaitingFlags;
use App\Modules\Manufacturing\Actions\Workshop\WorkshopDevices;
use App\Modules\Manufacturing\Actions\Workshop\WorkshopPeople;
use App\Modules\Manufacturing\Domain\LabourSource;
use App\Modules\Manufacturing\Domain\WaitingKind;
use App\Modules\Manufacturing\Http\Middleware\AuthenticateWorkshopPerson;
use App\Modules\Manufacturing\Models\ProductionLabourEntry;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Models\WorkshopPerson;
use App\Modules\Manufacturing\Models\WorkshopTimeEntry;
use App\Modules\Manufacturing\Models\WorkshopWaitingFlag;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\Manufacturing;
use Tests\Support\Workshop;

/*
 * The workshop terminal (ADR-071 §7): pairing a tablet, signing a worker in
 * with a personal code, the open orders, reporting production time as
 * append-only entries (a correction is a new entry), what each person sees,
 * and operations flagged as waiting.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));
    ['owner' => $this->owner, 'company' => $this->company, 'places' => $this->places] = Manufacturing::company();
    $this->actingAs($this->owner);
    $this->fx = Manufacturing::fixtures($this->company, $this->owner);
    $this->fx['articles']['table']->forceFill(['is_stock_item' => true])->save();
    Workshop::enable($this->company, $this->owner);
    $this->anna = Workshop::person($this->owner, $this->company, 'Anna Lind', '2580');
    $this->bo = Workshop::person($this->owner, $this->company, 'Bo Berg', '1397');
    [$this->device, $this->token] = Workshop::device($this->owner, $this->company);
    $this->order = Workshop::order($this, '2', true, ['due_on' => '2026-11-20']);
    $this->cut = $this->order->operations()->orderBy('position')->first();

    // Terminal requests have no session: forget the test's company, the token decides.
    CurrentCompany::forget();
});

function wsCall(object $test, string $route, array $data = [], ?string $session = null, ?string $token = null, string $method = 'post'): TestResponse
{
    $headers = array_filter([AuthenticateWorkshopPerson::HEADER => $session]);

    return $test->withToken($token ?? $test->token)->withHeaders($headers)->{$method.'Json'}(route("manufacturing.terminal.{$route}"), $data);
}

function wsSignIn(object $test, WorkshopPerson $person, string $code): string
{
    $response = wsCall($test, 'sign-in', ['person_id' => $person->id, 'code' => $code]);
    $response->assertOk()->assertJsonPath('ok', true);

    return (string) $response->json('token');
}

function wsReport(object $test, string $session, int $operationId, int $hours, int $minutes): TestResponse
{
    return wsCall($test, 'report', ['operation_id' => $operationId, 'hours' => $hours, 'minutes' => $minutes], $session);
}

// ---------- pairing and the device token ----------

it('pairs a tablet with a one-time code and keeps only the hash of its token', function () {
    CurrentCompany::set($this->company);
    [$device, $code] = app(WorkshopDevices::class)->register($this->owner, $this->company, 'Lackeringen');
    CurrentCompany::forget();

    $this->postJson(route('manufacturing.terminal.pair'), ['code' => 'ZZZZ-ZZZZ'])->assertStatus(422)->assertJsonPath('error', 'invalid_code');

    $token = $this->postJson(route('manufacturing.terminal.pair'), ['code' => strtolower($code)])->assertOk()->json('token');

    expect(strlen($token))->toBe(64);
    $stored = CompanyScope::bypass(fn () => WorkshopDevice::query()->find($device->id));
    expect($stored->token_hash)->toBe(hash('sha256', $token))
        ->and($stored->pairing_code_hash)->toBeNull()
        ->and(json_encode($stored->toArray()))->not->toContain($token);

    // Single use.
    $this->postJson(route('manufacturing.terminal.pair'), ['code' => $code])->assertStatus(422);
});

it('refuses an expired pairing code, an unknown token and a revoked tablet', function () {
    CurrentCompany::set($this->company);
    [, $code] = app(WorkshopDevices::class)->register($this->owner, $this->company, 'Gammal');
    CurrentCompany::forget();
    $this->travel(16)->minutes();

    $this->postJson(route('manufacturing.terminal.pair'), ['code' => $code])->assertStatus(422);
    wsCall($this, 'people', token: Str::random(64), method: 'get')->assertUnauthorized()->assertJsonPath('error', 'device_unknown');
    $this->getJson(route('manufacturing.terminal.people'))->assertUnauthorized();

    wsCall($this, 'people', method: 'get')->assertOk();
    CurrentCompany::set($this->company);
    app(WorkshopDevices::class)->revoke($this->owner, $this->company, $this->device);
    CurrentCompany::forget();

    wsCall($this, 'people', method: 'get')->assertUnauthorized();
});

it('answers 404 when the module or the terminal is switched off', function () {
    CurrentCompany::set($this->company);
    app(ModuleManager::class)->updateSettings('manufacturing', $this->company, $this->owner, ['terminal_enabled' => false]);
    CurrentCompany::forget();

    wsCall($this, 'people', method: 'get')->assertNotFound()->assertJsonPath('error', 'terminal_off');

    CurrentCompany::set($this->company);
    app(ModuleManager::class)->updateSettings('manufacturing', $this->company, $this->owner, ['terminal_enabled' => true]);
    app(ModuleManager::class)->disable('manufacturing', $this->company, $this->owner, confirmed: true);
    CurrentCompany::forget();

    wsCall($this, 'people', method: 'get')->assertNotFound()->assertJsonPath('error', 'module_off');
});

it('serves the page, manifest, icon and service worker without a sign-in', function () {
    $this->get('/verkstad/terminal')->assertOk()->assertSee('workshop-config', false)->assertHeader('Cache-Control');
    $this->get('/verkstad/terminal/manifest')->assertOk()->assertJsonPath('scope', '/verkstad/terminal/');
    $this->get('/verkstad/terminal/ikon')->assertOk();
    $this->get('/verkstad/terminal/serviceworker')->assertOk()->assertHeader('Service-Worker-Allowed', '/verkstad/terminal/');
});

// ---------- people and codes ----------

it('lists active people with a code, with a photo only when they have consented', function () {
    CurrentCompany::set($this->company);
    Workshop::person($this->owner, $this->company, 'Utan Kod', null);
    app(WorkshopPeople::class)->archive($this->owner, $this->company, Workshop::person($this->owner, $this->company, 'Arkiverad Person'), true);
    CurrentCompany::forget();

    $people = wsCall($this, 'people', method: 'get')->assertOk()->json('people');

    expect(collect($people)->pluck('name')->all())->toBe(['Anna Lind', 'Bo Berg'])
        ->and($people[0])->toHaveKeys(['id', 'name', 'photo'])
        ->and($people[0]['photo'])->toBeNull()
        ->and(json_encode($people))->not->toContain('verifier')->not->toContain('salt');
});

it('never stores the code and refuses easy ones', function () {
    $person = WorkshopPerson::query()->withoutGlobalScopes()->find($this->anna->id);

    expect($person->code_verifier)->not->toContain('2580')
        ->and($person->code_salt)->toHaveLength(32)
        ->and(fn () => Workshop::person($this->owner, $this->company, 'Trivial', '1234'))->toThrow(ValidationException::class, 'svårare att gissa')
        ->and(fn () => Workshop::person($this->owner, $this->company, 'Kort', '12'))->toThrow(ValidationException::class, 'fyra siffror');
});

it('signs a worker in with the right code and locks the person after five wrong ones', function () {
    wsCall($this, 'sign-in', ['person_id' => $this->anna->id, 'code' => '9999'])->assertOk()->assertJsonPath('ok', false)->assertJsonPath('locked', false);

    $session = wsSignIn($this, $this->anna, '2580');
    expect($session)->toContain('.');

    foreach (range(1, 4) as $_) {
        wsCall($this, 'sign-in', ['person_id' => $this->anna->id, 'code' => '9999'])->assertOk()->assertJsonPath('ok', false);
    }
    wsCall($this, 'sign-in', ['person_id' => $this->anna->id, 'code' => '9999'])->assertStatus(429)->assertJsonPath('locked', true);
    // Locked, also with the right code.
    wsCall($this, 'sign-in', ['person_id' => $this->anna->id, 'code' => '2580'])->assertStatus(429);
    // And the session from before no longer works.
    wsCall($this, 'orders', session: $session, method: 'get')->assertUnauthorized();

    $this->travel(6)->minutes();
    wsSignIn($this, $this->anna, '2580');

    // Another person is not affected.
    wsSignIn($this, $this->bo, '1397');
});

it('refuses a missing, forged, expired or other tablet\'s session, and one made before a new code', function () {
    $session = wsSignIn($this, $this->anna, '2580');

    wsCall($this, 'orders', method: 'get')->assertUnauthorized()->assertJsonPath('error', 'session_ended');
    wsCall($this, 'orders', session: $session.'x', method: 'get')->assertUnauthorized();
    wsCall($this, 'orders', session: 'abc.def', method: 'get')->assertUnauthorized();
    wsCall($this, 'orders', session: $session, method: 'get')->assertOk();

    CurrentCompany::set($this->company);
    [, $otherToken] = Workshop::device($this->owner, $this->company, 'Lackeringen');
    CurrentCompany::forget();
    wsCall($this, 'orders', session: $session, token: $otherToken, method: 'get')->assertUnauthorized();

    $this->travel(11)->minutes();
    wsCall($this, 'orders', session: $session, method: 'get')->assertUnauthorized();

    $fresh = wsSignIn($this, $this->anna, '2580');
    CurrentCompany::set($this->company);
    app(WorkshopPeople::class)->setCode($this->owner, $this->company, $this->anna, '7391');
    CurrentCompany::forget();
    wsCall($this, 'orders', session: $fresh, method: 'get')->assertUnauthorized();
    wsSignIn($this, $this->anna, '7391');
});

// ---------- the order list ----------

it('lists released and started orders with product, customer and quantity, and nothing else', function () {
    CurrentCompany::set($this->company);
    $draft = Workshop::order($this, '1', false);
    $document = Manufacturing::document($this->company, $this->owner, [['BORD-180', '3', '12000,00', 'Ekbord 180']], SalesDocument::ORDER);
    $document->forceFill(['your_reference' => 'Maria på Kunden'])->save();
    $forCustomer = Workshop::order($this, '3', true, ['sales_document_id' => $document->id, 'line_key' => $document->rows[0]->line_key, 'article_id' => null, 'quantity' => null, 'location_id' => $this->places['bin2']->id]);
    CurrentCompany::forget();

    $session = wsSignIn($this, $this->anna, '2580');
    $orders = wsCall($this, 'orders', session: $session, method: 'get')->assertOk()->json('orders');

    expect(collect($orders)->pluck('reference')->all())->toBe([$this->order->reference(), $forCustomer->reference()])
        ->and(collect($orders)->pluck('reference')->all())->not->toContain($draft->reference())
        ->and($orders[0])->toMatchArray(['product' => 'Ekbord 180', 'quantity' => '2 st', 'due_on' => '2026-11-20', 'customer' => null])
        ->and($orders[1])->toMatchArray(['customer' => 'Exempelkunden AB', 'your_reference' => 'Maria på Kunden', 'quantity' => '3 st'])
        ->and(collect($orders[0]['operations'])->pluck('name')->all())->toBe(['Kapning', 'Montering', 'Lackering'])
        ->and(collect($orders[0]['operations'])->pluck('planned_minutes')->all())->toBe([60, 180, 60]);
});

it('drops an order from the terminal when it is finished', function () {
    Manufacturing::stock($this->company, $this->owner, $this->fx, $this->places['bin1']);
    CurrentCompany::set($this->company);
    app(ProductionOrderLifecycle::class)->start($this->owner, $this->company, $this->order);
    app(ProductionOrderLifecycle::class)->finish($this->owner, $this->company, $this->order->refresh());
    CurrentCompany::forget();

    $session = wsSignIn($this, $this->anna, '2580');
    expect(wsCall($this, 'orders', session: $session, method: 'get')->json('orders'))->toBe([]);
});

// ---------- reporting time ----------

it('reports hours and minutes for today as a labour entry priced at the hourly cost and a time entry for the person', function () {
    $session = wsSignIn($this, $this->anna, '2580');

    $response = wsReport($this, $session, $this->cut->id, 1, 30)->assertOk()->assertJsonPath('ok', true);

    CurrentCompany::set($this->company);
    $labour = ProductionLabourEntry::query()->where('production_order_operation_id', $this->cut->id)->sole();
    $entry = WorkshopTimeEntry::query()->sole();

    // 90 min of Kapning at 450,00/h.
    expect($labour->source)->toBe(LabourSource::Terminal)
        ->and($labour->minutes)->toBe(90)->and($labour->hourly_cost_ore)->toBe(45_000)->and($labour->cost_ore)->toBe(67_500)
        ->and($labour->worked_on->toDateString())->toBe('2026-11-10')
        ->and($labour->created_by)->toBeNull()
        ->and($entry->person_id)->toBe($this->anna->id)->and($entry->device_id)->toBe($this->device->id)
        ->and($entry->production_order_id)->toBe($this->order->id)->and($entry->production_order_operation_id)->toBe($this->cut->id)
        ->and($entry->production_labour_entry_id)->toBe($labour->id)
        ->and($entry->minutes)->toBe(90)->and($entry->worked_on->toDateString())->toBe('2026-11-10')
        ->and($entry->entered_at->toDateTimeString())->toBe('2026-11-10 10:00:00');

    $operation = collect($response->json('orders.0.operations'))->firstWhere('id', $this->cut->id);
    expect($operation)->toMatchArray(['reported_minutes' => 90, 'my_minutes_today' => 90])
        ->and($response->json('mine.0'))->toMatchArray(['minutes' => 90, 'can_correct' => true, 'is_correction' => false]);

    $audit = AuditLog::query()->where('action', 'manufacturing.terminal_time_entered')->latest('id')->first();
    expect($audit->payload)->toMatchArray(['minutes' => 90, 'device_id' => $this->device->id, 'production_order_operation_id' => $this->cut->id])
        ->and(json_encode($audit->payload))->not->toContain((string) $this->anna->id)->not->toContain('Anna')
        ->and($audit->ip_address)->toBeNull();
});

it('refuses times that are zero, too long, or not whole numbers', function (int $hours, int $minutes) {
    $session = wsSignIn($this, $this->anna, '2580');

    wsReport($this, $session, $this->cut->id, $hours, $minutes)->assertStatus(422)->assertJsonPath('error', 'rejected');
    expect(WorkshopTimeEntry::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([[0, 0], [16, 1], [17, 0], [1, 60], [-1, 30]]);

it('refuses non-integer input and an operation that is not there', function () {
    $session = wsSignIn($this, $this->anna, '2580');

    wsCall($this, 'report', ['operation_id' => $this->cut->id, 'hours' => '1', 'minutes' => 0], $session)->assertStatus(422)->assertJsonPath('error', 'invalid');
    wsReport($this, $session, 999999, 1, 0)->assertStatus(422)->assertJsonPath('error', 'rejected');
});

it('takes time only on released or started orders', function () {
    CurrentCompany::set($this->company);
    $draft = Workshop::order($this, '1', false)->operations()->first();
    CurrentCompany::forget();
    $session = wsSignIn($this, $this->anna, '2580');

    wsReport($this, $session, $draft->id, 1, 0)->assertStatus(422)->assertJsonPath('message', 'Ordern är inte öppen för tid. Fråga arbetsledaren.');

    Manufacturing::stock($this->company, $this->owner, $this->fx, $this->places['bin1']);
    CurrentCompany::set($this->company);
    app(ProductionOrderLifecycle::class)->start($this->owner, $this->company, $this->order);
    app(ProductionOrderLifecycle::class)->finish($this->owner, $this->company, $this->order->refresh());
    CurrentCompany::forget();

    wsReport($this, $session, $this->cut->id, 1, 0)->assertStatus(422);
});

it('counts the reported minutes as the actual time when the order is finished', function () {
    $session = wsSignIn($this, $this->anna, '2580');
    wsReport($this, $session, $this->cut->id, 1, 30)->assertOk();
    Manufacturing::stock($this->company, $this->owner, $this->fx, $this->places['bin1']);

    CurrentCompany::set($this->company);
    app(ProductionOrderLifecycle::class)->start($this->owner, $this->company, $this->order);
    app(ProductionOrderLifecycle::class)->finish($this->owner, $this->company, $this->order->refresh());

    $minutes = ProductionLabourEntry::query()->where('production_order_id', $this->order->id)->groupBy('production_order_operation_id', 'source')
        ->selectRaw('production_order_operation_id, source, sum(minutes) as minutes')->get()
        ->mapWithKeys(fn ($row) => [$row->production_order_operation_id.'-'.$row->source->value => (int) $row->minutes]);

    // Kapning keeps the 90 reported terminal minutes and is not topped up with its 60 planned ones; the others get their plan.
    expect($minutes->get($this->cut->id.'-TERMINAL'))->toBe(90)
        ->and($minutes->has($this->cut->id.'-PLANNED'))->toBeFalse()
        ->and($minutes->filter(fn ($value, $key) => str_ends_with($key, '-PLANNED'))->values()->all())->toBe([180, 60]);
});

// ---------- corrections and what each person sees ----------

it('corrects an own report with a new entry that cancels it, once, and leaves the original', function () {
    $session = wsSignIn($this, $this->anna, '2580');
    wsReport($this, $session, $this->cut->id, 2, 0)->assertOk();
    $entryId = wsCall($this, 'orders', session: $session, method: 'get')->json('mine.0.id');

    $response = wsCall($this, 'correct', ['entry_id' => $entryId], $session)->assertOk();

    CurrentCompany::set($this->company);
    $entries = WorkshopTimeEntry::query()->orderBy('id')->get();
    $labour = ProductionLabourEntry::query()->where('production_order_operation_id', $this->cut->id)->orderBy('id')->get();

    expect($entries->pluck('minutes')->all())->toBe([120, -120])
        ->and($entries[1]->corrects_entry_id)->toBe($entries[0]->id)
        ->and($entries[1]->person_id)->toBe($this->anna->id)
        ->and($labour->pluck('minutes')->all())->toBe([120, -120])
        ->and($labour->sum('cost_ore'))->toBe(0)
        ->and($labour->pluck('cost_ore')->all())->toBe([90_000, -90_000])
        ->and(collect($response->json('orders.0.operations'))->firstWhere('id', $this->cut->id)['reported_minutes'])->toBe(0)
        ->and(collect($response->json('mine'))->pluck('can_correct')->all())->toBe([false, false])
        ->and(collect($response->json('mine'))->pluck('is_correction')->all())->toBe([true, false]);

    wsCall($this, 'correct', ['entry_id' => $entryId], $session)->assertStatus(422)->assertJsonPath('message', 'Den tiden är redan rättad.');
    wsCall($this, 'correct', ['entry_id' => $entries[1]->id], $session)->assertStatus(422);
    expect(AuditLog::query()->where('action', 'manufacturing.terminal_time_corrected')->count())->toBe(1);
});

it('lets each person see and correct only their own reports', function () {
    $annaSession = wsSignIn($this, $this->anna, '2580');
    $boSession = wsSignIn($this, $this->bo, '1397');
    wsReport($this, $annaSession, $this->cut->id, 1, 0)->assertOk();
    $response = wsReport($this, $boSession, $this->cut->id, 0, 45)->assertOk();

    // Both are in the order's total; each sees only their own minutes and list.
    $operation = collect($response->json('orders.0.operations'))->firstWhere('id', $this->cut->id);
    expect($operation)->toMatchArray(['reported_minutes' => 105, 'my_minutes_today' => 45])
        ->and(collect($response->json('mine'))->pluck('minutes')->all())->toBe([45]);

    $annasEntry = wsCall($this, 'orders', session: $annaSession, method: 'get')->json('mine.0.id');
    wsCall($this, 'correct', ['entry_id' => $annasEntry], $boSession)->assertStatus(422)->assertJsonPath('message', 'Den tiden går inte att rätta.');
    expect(json_encode($response->json()))->not->toContain('Anna')->not->toContain('person');
});

it('shows only the last two weeks of a person\'s own reports', function () {
    $session = wsSignIn($this, $this->anna, '2580');
    wsReport($this, $session, $this->cut->id, 1, 0)->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00:00'));
    $session = wsSignIn($this, $this->anna, '2580');

    expect(wsCall($this, 'orders', session: $session, method: 'get')->json('mine'))->toBe([]);
});

// ---------- append-only ----------

it('never lets a time entry be changed or deleted, except removing its person after retention', function () {
    $session = wsSignIn($this, $this->anna, '2580');
    wsReport($this, $session, $this->cut->id, 1, 0)->assertOk();
    CurrentCompany::set($this->company);
    $entry = WorkshopTimeEntry::query()->sole();

    expect(fn () => $entry->forceFill(['minutes' => 5])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);

    foreach ([
        'update workshop_time_entries set minutes = 5',
        'update workshop_time_entries set worked_on = \'2026-01-01\'',
        'update workshop_time_entries set person_id = '.$this->bo->id,
        'update workshop_time_entries set person_id = null, minutes = 5',
        'delete from workshop_time_entries',
    ] as $sql) {
        expect(fn () => DB::transaction(fn () => DB::statement($sql)))->toThrow(QueryException::class);
    }

    DB::transaction(fn () => DB::statement('update workshop_time_entries set person_id = null'));
    expect(WorkshopTimeEntry::query()->sole()->person_id)->toBeNull();

    // The labour entry of M4 is as append-only as before.
    expect(fn () => ProductionLabourEntry::query()->sole()->forceFill(['minutes' => 1])->save())->toThrow(LogicException::class);
});

// ---------- waiting ----------

it('flags an operation as waiting for material or work with a reason, one open flag per operation', function () {
    $session = wsSignIn($this, $this->anna, '2580');

    wsCall($this, 'wait', ['operation_id' => $this->cut->id, 'kind' => 'MATERIAL', 'reason' => 'Ekskivorna är inte levererade'], $session)->assertOk();
    $operation = collect(wsCall($this, 'orders', session: $session, method: 'get')->json('orders.0.operations'))->firstWhere('id', $this->cut->id);
    expect($operation['waiting'])->toMatchArray(['kind' => 'MATERIAL', 'label' => 'Väntar på material', 'reason' => 'Ekskivorna är inte levererade']);

    // A new flag replaces the old one.
    wsCall($this, 'wait', ['operation_id' => $this->cut->id, 'kind' => 'WORK', 'reason' => 'Sågen är upptagen'], $session)->assertOk();
    CurrentCompany::set($this->company);
    expect(WorkshopWaitingFlag::query()->count())->toBe(2)
        ->and(WorkshopWaitingFlag::query()->open()->count())->toBe(1)
        ->and(WorkshopWaitingFlag::query()->open()->sole()->kind->value)->toBe('WORK')
        ->and(WorkshopWaitingFlag::query()->open()->sole()->raised_by_device_id)->toBe($this->device->id)
        ->and(Schema::getColumnListing('workshop_waiting_flags'))->not->toContain('person_id')
        ->and(json_encode(AuditLog::query()->where('action', 'manufacturing.terminal_waiting_raised')->get()->pluck('payload')))->not->toContain('Sågen');
    CurrentCompany::forget();

    wsCall($this, 'clear-wait', ['operation_id' => $this->cut->id], $session)->assertOk();
    CurrentCompany::set($this->company);
    expect(WorkshopWaitingFlag::query()->open()->count())->toBe(0);
});

it('refuses a flag without a reason, with a long one, with an unknown kind, or on a closed order', function () {
    $session = wsSignIn($this, $this->anna, '2580');

    wsCall($this, 'wait', ['operation_id' => $this->cut->id, 'kind' => 'MATERIAL', 'reason' => '  '], $session)->assertStatus(422);
    wsCall($this, 'wait', ['operation_id' => $this->cut->id, 'kind' => 'MATERIAL', 'reason' => str_repeat('x', 201)], $session)->assertStatus(422);
    wsCall($this, 'wait', ['operation_id' => $this->cut->id, 'kind' => 'LUNCH', 'reason' => 'x'], $session)->assertStatus(422)->assertJsonPath('error', 'invalid');
    expect(WorkshopWaitingFlag::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('clears the flag by itself when someone reports time on the operation', function () {
    $session = wsSignIn($this, $this->anna, '2580');
    wsCall($this, 'wait', ['operation_id' => $this->cut->id, 'kind' => 'MATERIAL', 'reason' => 'Väntar på skivor'], $session)->assertOk();
    $this->travel(3)->hours();
    $session = wsSignIn($this, $this->anna, '2580');

    $response = wsReport($this, $session, $this->cut->id, 0, 20)->assertOk();

    expect(collect($response->json('orders.0.operations'))->firstWhere('id', $this->cut->id)['waiting'])->toBeNull();
    CurrentCompany::set($this->company);
    $flag = WorkshopWaitingFlag::query()->sole();
    expect($flag->cleared_at)->not->toBeNull()->and($flag->cleared_by_device_id)->toBe($this->device->id);
});

// ---------- devices ----------

it('removes a tablet that never reported, and only hides one that did', function () {
    CurrentCompany::set($this->company);
    [$fresh] = app(WorkshopDevices::class)->register($this->owner, $this->company, 'Ny');
    app(WorkshopDevices::class)->remove($this->owner, $this->company, $fresh);
    expect(WorkshopDevice::query()->find($fresh->id))->toBeNull();
    CurrentCompany::forget();

    $session = wsSignIn($this, $this->anna, '2580');
    wsReport($this, $session, $this->cut->id, 1, 0)->assertOk();

    CurrentCompany::set($this->company);
    app(WorkshopDevices::class)->remove($this->owner, $this->company, $this->device);
    $kept = WorkshopDevice::query()->find($this->device->id);
    expect($kept)->not->toBeNull()->and($kept->removed_at)->not->toBeNull()->and($kept->token_hash)->toBeNull();
    CurrentCompany::forget();

    wsCall($this, 'people', method: 'get')->assertUnauthorized();
});

it('uses RecordTerminalTime for corrections only on the person\'s own entries', function () {
    CurrentCompany::set($this->company);
    $entry = app(RecordTerminalTime::class)($this->device, $this->anna, $this->cut->id, 1, 0);

    expect(fn () => app(RecordTerminalTime::class)->correct($this->device, $this->bo, $entry->id))->toThrow(ValidationException::class);
});

// ---------- queries ----------

it('runs a fixed number of queries for the order list and the person\'s own reports', function () {
    $count = function () use (&$session): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        wsCall($this, 'orders', session: $session, method: 'get')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $session = wsSignIn($this, $this->anna, '2580');

    CurrentCompany::set($this->company);
    $document = Manufacturing::document($this->company, $this->owner, [['BORD-180', '3', '12000,00', 'Ekbord 180']], SalesDocument::ORDER);
    $add = function () use ($document): void {
        $order = Workshop::order($this, '1', true, ['sales_document_id' => $document->id, 'line_key' => $document->rows[0]->line_key, 'article_id' => null, 'quantity' => null, 'location_id' => $this->places['bin2']->id]);
        app(RecordTerminalTime::class)($this->device, $this->anna, $order->operations()->first()->id, 0, 15);
        app(WaitingFlags::class)->raise($this->device, $order->operations()->orderBy('position')->get()->last()->id, WaitingKind::Work, 'Väntar');
    };

    // One order with a customer, own time and a flag loads every relation once; more orders add no queries.
    $add();
    CurrentCompany::forget();
    $few = $count();

    CurrentCompany::set($this->company);
    foreach (range(1, 8) as $_) {
        $add();
    }
    CurrentCompany::forget();

    expect(wsCall($this, 'orders', session: $session, method: 'get')->json('orders'))->toHaveCount(10)
        ->and($count())->toBe($few)->and($few)->toBeLessThanOrEqual(25);
});
