<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Actions\Workshop\RecordTerminalTime;
use App\Modules\Manufacturing\Actions\Workshop\WaitingFlags;
use App\Modules\Manufacturing\Domain\WaitingKind;
use App\Modules\Manufacturing\Http\Middleware\AuthenticateWorkshopPerson;
use App\Modules\Manufacturing\Livewire\Workshop\TerminalAdminPage;
use App\Modules\Manufacturing\Models\ProductionLabourEntry;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Models\WorkshopPerson;
use App\Modules\Manufacturing\Models\WorkshopTimeEntry;
use App\Modules\Manufacturing\Models\WorkshopWaitingFlag;
use App\Modules\Manufacturing\Support\WorkshopAnalysis;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\Manufacturing;
use Tests\Support\Workshop;

/*
 * AGENTS.md §13.2 for the workshop terminal (ADR-071 §7): a tablet paired to
 * company A reaches only A's people, orders and entries; B's ids answer
 * "not found" and write nothing; and the pages never show B's tablets.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));

    foreach (['a', 'b'] as $side) {
        ['owner' => $owner, 'company' => $company, 'places' => $places] = Manufacturing::company();
        $this->actingAs($owner);
        $this->owner = $owner;
        $this->company = $company;
        $this->places = $places;
        $this->fx = Manufacturing::fixtures($company, $owner);
        $this->fx['articles']['table']->forceFill(['is_stock_item' => true])->save();
        Workshop::enable($company, $owner);
        $person = Workshop::person($owner, $company, $side === 'a' ? 'Anna Lind' : 'Bertil Berg');
        [$device, $token] = Workshop::device($owner, $company, "Tablet {$side}");
        $order = Workshop::order($this);
        $operation = $order->operations()->orderBy('position')->first();
        $entry = app(RecordTerminalTime::class)($device, $person, $operation->id, 1, 0);
        app(WaitingFlags::class)->raise($device, $operation->id, WaitingKind::Material, "Skivor {$side}");
        $this->{$side} = compact('owner', 'company', 'person', 'device', 'token', 'order', 'operation', 'entry');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

function tenancyCall(string $token, string $route, array $data = [], ?string $session = null, string $method = 'post'): TestResponse
{
    CurrentCompany::forget();

    return test()->withToken($token)->withHeaders(array_filter([AuthenticateWorkshopPerson::HEADER => $session]))->{$method.'Json'}(route("manufacturing.terminal.{$route}"), $data);
}

function tenancySession(array $side, string $code = '2580'): string
{
    return (string) tenancyCall($side['token'], 'sign-in', ['person_id' => $side['person']->id, 'code' => $code])->json('token');
}

it('scopes every workshop model to the current company', function (string $model) {
    expect($model::query()->count())->toBe(1)
        ->and($model::query()->pluck('company_id')->unique()->all())->toBe([$this->a['company']->id]);
})->with([WorkshopPerson::class, WorkshopDevice::class, WorkshopTimeEntry::class, WorkshopWaitingFlag::class]);

it('shows a paired tablet only its own company\'s people and orders', function () {
    $people = tenancyCall($this->a['token'], 'people', method: 'get')->assertOk()->json('people');
    $orders = tenancyCall($this->a['token'], 'orders', session: tenancySession($this->a), method: 'get')->assertOk()->json('orders');

    expect(collect($people)->pluck('name')->all())->toBe(['Anna Lind'])
        ->and(collect($orders)->pluck('id')->all())->toBe([$this->a['order']->id])
        ->and(json_encode([$people, $orders]))->not->toContain('Bertil')->not->toContain('Skivor b');
});

it('cannot sign in the other company\'s person, and a session from one company means nothing in the other', function () {
    tenancyCall($this->a['token'], 'sign-in', ['person_id' => $this->b['person']->id, 'code' => '2580'])->assertOk()->assertJsonPath('ok', false);

    $session = tenancySession($this->a);
    tenancyCall($this->b['token'], 'orders', session: $session, method: 'get')->assertUnauthorized();
});

it('writes nothing on the other company\'s order, entry or operation', function () {
    $session = tenancySession($this->a);
    $before = [WorkshopTimeEntry::query()->withoutGlobalScopes()->count(), ProductionLabourEntry::query()->withoutGlobalScopes()->count()];

    tenancyCall($this->a['token'], 'report', ['operation_id' => $this->b['operation']->id, 'hours' => 1, 'minutes' => 0], $session)->assertStatus(422);
    tenancyCall($this->a['token'], 'correct', ['entry_id' => $this->b['entry']->id], $session)->assertStatus(422);
    tenancyCall($this->a['token'], 'wait', ['operation_id' => $this->b['operation']->id, 'kind' => 'WORK', 'reason' => 'x'], $session)->assertStatus(422);
    tenancyCall($this->a['token'], 'clear-wait', ['operation_id' => $this->b['operation']->id], $session)->assertOk();

    expect([WorkshopTimeEntry::query()->withoutGlobalScopes()->count(), ProductionLabourEntry::query()->withoutGlobalScopes()->count()])->toBe($before)
        ->and(WorkshopWaitingFlag::query()->withoutGlobalScopes()->whereNull('cleared_at')->where('company_id', $this->b['company']->id)->count())->toBe(1);
});

it('answers 404 for the other company\'s tablet and person on the admin page', function () {
    CurrentCompany::set($this->a['company']);

    Livewire::test(TerminalAdminPage::class)
        ->assertSee('Tablet a')->assertDontSee('Tablet b')->assertSee('Anna Lind')->assertDontSee('Bertil')
        ->call('revokeDevice', $this->b['device']->id)->assertNotFound();
    Livewire::test(TerminalAdminPage::class)->call('startCode', $this->b['person']->id)->assertNotFound();
    Livewire::test(TerminalAdminPage::class)->call('deletePerson', $this->b['person']->id)->assertNotFound();

    expect(WorkshopDevice::query()->withoutGlobalScopes()->find($this->b['device']->id)->revoked_at)->toBeNull();
});

it('keeps the post-calculation inside the company', function () {
    CurrentCompany::set($this->a['company']);
    $analysis = WorkshopAnalysis::for(12);

    expect(collect($analysis['waiting']['material'])->pluck('reason')->all())->toBe(['Skivor a'])
        ->and(collect($analysis['queue'])->sum('orders'))->toBe(2);
});

it('lets a tablet reach only its own company when the other one switches the terminal off', function () {
    CurrentCompany::set($this->b['company']);
    app(ModuleManager::class)->updateSettings('manufacturing', $this->b['company'], $this->b['owner'], ['terminal_enabled' => false]);

    tenancyCall($this->b['token'], 'people', method: 'get')->assertNotFound();
    tenancyCall($this->a['token'], 'people', method: 'get')->assertOk();
});
