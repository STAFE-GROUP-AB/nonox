<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Manufacturing\Actions\Workshop\WaitingFlags;
use App\Modules\Manufacturing\Actions\Workshop\WorkshopDevices;
use App\Modules\Manufacturing\Domain\DeviceKind;
use App\Modules\Manufacturing\Domain\DowntimeReason;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\Domain\WaitingKind;
use App\Modules\Manufacturing\Livewire\Workshop\TerminalAdminPage;
use App\Modules\Manufacturing\Livewire\Workshop\WallScreenAdminPage;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Models\Downtime;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\ProductionPlanner;
use App\Modules\Manufacturing\Support\WallBoard;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Absence;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Manufacturing;
use Tests\Support\PlanningWorkshop;
use Tests\Support\Workshop;

/*
 * The workshop wall screen (ADR-071, M7): paired like a terminal tablet but
 * as its own kind, it shows today's planned work, the late orders, the
 * coming deliveries and what waits or stands still, from the latest plan,
 * in a fixed number of queries. It names no person, no absence, no
 * customer and no waiting reason.
 */

beforeEach(function () {
    ['owner' => $this->owner, 'company' => $this->company] = Manufacturing::company();
    // Monday 2026-11-16 09:30, the fixture workshop of §13.9 (F-P0), a few hours into the day.
    $this->now = CarbonImmutable::parse('2026-11-16 09:30', Clock::ZONE);
    $this->travelTo($this->now);
    $this->shop = PlanningWorkshop::build($this->company, $this->owner);
    $this->actingAs($this->owner);
});

function wallScreen(object $test): array
{
    $devices = app(WorkshopDevices::class);
    [, $code] = $devices->register($test->owner, $test->company, 'Tv:n i verkstaden', DeviceKind::WallScreen);
    CurrentCompany::forget();
    $paired = $devices->pair($code, DeviceKind::WallScreen) ?? throw new RuntimeException('The pairing code was refused.');
    CurrentCompany::set($test->company);

    return $paired;
}

function wallBoard(object $test, string $token): TestResponse
{
    CurrentCompany::forget();
    $response = $test->withToken($token)->getJson(route('manufacturing.wallScreen.board'));
    CurrentCompany::set($test->company);

    return $response;
}

function wallPlan(object $test): void
{
    app(ProductionPlanner::class)->run($test->company, PlanTrigger::Manual, CarbonImmutable::parse('2026-11-16 07:00', Clock::ZONE));
}

it('serves the page to anyone, without company data or a session', function () {
    auth()->logout();

    $this->get(route('manufacturing.wallScreen'))
        ->assertOk()
        ->assertSee('Storskärm i verkstaden')
        ->assertSee(route('manufacturing.wallScreen.board', absolute: false))
        ->assertDontSee($this->company->name)
        ->assertHeader('Content-Security-Policy');
});

it('pairs a wall screen with a one-time code that only the wall screen page accepts', function () {
    $devices = app(WorkshopDevices::class);
    [$screen, $code] = $devices->register($this->owner, $this->company, 'Tv:n i verkstaden', DeviceKind::WallScreen);

    expect($screen->kind)->toBe(DeviceKind::WallScreen);

    // The terminal does not take a wall screen's code, and its page answers as for a wrong code.
    CurrentCompany::forget();
    $this->postJson(route('manufacturing.terminal.pair'), ['code' => $code])->assertStatus(422);

    $paired = $this->postJson(route('manufacturing.wallScreen.pair'), ['code' => $code])->assertOk();
    $token = (string) $paired->json('token');

    // Single use.
    $this->postJson(route('manufacturing.wallScreen.pair'), ['code' => $code])->assertStatus(422);

    $stored = CompanyScope::bypass(fn () => WorkshopDevice::query()->find($screen->id));
    expect($stored->token_hash)->toBe(hash('sha256', $token))
        ->and($stored->paired_at)->not->toBeNull();

    expect(CompanyScope::bypass(fn () => AuditLog::query()->where('company_id', $this->company->id)->whereIn('action', ['manufacturing.wall_screen_registered', 'manufacturing.wall_screen_paired'])->count()))->toBe(2);
});

it('keeps the two kinds of token apart', function () {
    Workshop::enable($this->company, $this->owner);
    [, $terminalToken] = Workshop::device($this->owner, $this->company);
    [, $wallToken] = wallScreen($this);
    CurrentCompany::forget();

    // A wall screen cannot list the terminal's people, and a tablet cannot read the board.
    $this->withToken($wallToken)->getJson(route('manufacturing.terminal.people'))->assertStatus(401);
    $this->withToken($terminalToken)->getJson(route('manufacturing.wallScreen.board'))->assertStatus(401)->assertJsonPath('error', 'device_unknown');
    $this->withToken($wallToken)->getJson(route('manufacturing.wallScreen.board'))->assertOk();
});

it('needs no terminal: the wall screen works with the terminal switched off', function () {
    wallPlan($this);
    [, $token] = wallScreen($this);

    wallBoard($this, $token)->assertOk()->assertJsonPath('company', $this->company->name);
});

it('shows today\'s planned work per production order, with the operation going on now', function () {
    wallPlan($this);
    [, $token] = wallScreen($this);

    $board = wallBoard($this, $token)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();

    expect($board['today'])->toBe('2026-11-16')
        ->and($board['run'])->not->toBeNull()
        ->and($board['work'])->not->toBeEmpty();

    $first = $board['work'][0];
    expect($first['reference'])->toStartWith('TO-')
        ->and($first['product'])->not->toBe('')
        ->and($first['quantity'])->toContain('st')
        ->and($first['status'])->toBe('GREEN')
        ->and($first['status_label'])->toBe('I tid');

    $steps = collect($board['work'])->flatMap(fn (array $order) => $order['operations']);
    expect($steps->pluck('name')->all())->toContain('Kapning')
        ->and($steps->where('now', true))->not->toBeEmpty()
        ->and($steps->every(fn (array $step) => preg_match('/^\d\d:\d\d$/', $step['from']) === 1 && preg_match('/^\d\d:\d\d$/', $step['to']) === 1))->toBeTrue();
});

it('names nobody: no person, no absence and no customer', function () {
    CurrentCompany::run($this->company, fn () => Absence::create([
        'employee_id' => $this->shop['people']['tove']->id, 'type' => 'SICK', 'date_from' => '2026-11-17', 'date_to' => '2026-11-23', 'status' => 'APPROVED', 'note' => 'Influensa',
    ]));
    wallPlan($this);
    [, $token] = wallScreen($this);

    $response = wallBoard($this, $token)->assertOk();
    // The company's own name is made up by the factory and may hold any of the words.
    $json = (string) json_encode(collect($response->json())->except('company')->all(), JSON_UNESCAPED_UNICODE);

    foreach (['Testsson', 'Tove', 'Maja', 'Omar', 'Jonas', 'Lina', 'Frånvarande', 'SICK', 'Sjuk', 'Influensa', 'employee', 'customer'] as $word) {
        expect(str_contains($json, $word))->toBeFalse("The board contains {$word}.");
    }

    // Tove's absence makes TO-1042 and TO-1041 late by three working days (F-P1), and the board says so.
    $late = collect($response->json('late'))->keyBy('reference');
    expect($late->keys()->sort()->values()->all())->toBe(['TO-1041', 'TO-1042'])
        ->and($late['TO-1042']['days_late'])->toBe(1)
        ->and($late['TO-1042']['promised_on'])->toBe('2026-11-24')
        ->and($late['TO-1042']['forecast_on'])->toBe('2026-11-25');
});

it('lists what is promised in the coming five working days, with the plan\'s light', function () {
    CurrentCompany::run($this->company, function (): void {
        $this->shop['orders'][1042]->forceFill(['due_on' => '2026-11-19'])->save();
        $this->shop['orders'][1043]->forceFill(['due_on' => '2026-11-23'])->save();
    });
    wallPlan($this);
    [, $token] = wallScreen($this);

    $deliveries = collect(wallBoard($this, $token)->json('deliveries'));

    // Monday to Friday: TO-1042 (Thursday, late by a day) is in, TO-1043 (next Monday) and TO-1041 (Wednesday after) are not.
    expect($deliveries->pluck('reference')->all())->toBe(['TO-1042'])
        ->and($deliveries[0]['promised_on'])->toBe('2026-11-19')
        ->and($deliveries[0]['status'])->toBe('RED')
        ->and($deliveries[0]['status_label'])->toBe('Försenad');
});

it('shows waiting operations without their reason, and stopped workstations', function () {
    Workshop::enable($this->company, $this->owner);
    [$tablet] = Workshop::device($this->owner, $this->company);
    $operation = $this->shop['orders'][1041]->operations()->orderBy('position')->first();
    app(WaitingFlags::class)->raise($tablet, $operation->id, WaitingKind::Material, 'Anna säger att skivorna är slut');
    CurrentCompany::run($this->company, fn () => Downtime::create([
        'workstation_id' => $this->shop['lackbox']->id, 'starts_at' => $this->now->subHour(), 'ends_at' => $this->now->setTime(14, 0), 'reason' => DowntimeReason::Breakdown, 'source' => Downtime::MANUAL,
    ]));
    wallPlan($this);
    [, $token] = wallScreen($this);

    $response = wallBoard($this, $token)->assertOk();

    $json = (string) json_encode(collect($response->json())->except('company')->all(), JSON_UNESCAPED_UNICODE);
    expect(str_contains($json, 'skivorna'))->toBeFalse()
        ->and(str_contains($json, 'Anna'))->toBeFalse();
    $response->assertJsonPath('waiting.0.reference', 'TO-1041')
        ->assertJsonPath('waiting.0.kind_label', 'Väntar på material')
        ->assertJsonPath('waiting.0.operation', 'Kapning')
        ->assertJsonPath('waiting.0.since', '09:30')
        ->assertJsonPath('stopped.0.workstation', 'Lackbox')
        ->assertJsonPath('stopped.0.until', '14:00');
});

it('tells a stop going on now from one planned for later today', function () {
    CurrentCompany::run($this->company, function (): void {
        Downtime::create(['workstation_id' => $this->shop['lackbox']->id, 'starts_at' => $this->now->setTime(13, 0), 'ends_at' => $this->now->setTime(15, 0), 'reason' => DowntimeReason::Maintenance, 'source' => Downtime::MANUAL]);
        Downtime::create(['workstation_id' => $this->shop['lackbox']->id, 'starts_at' => $this->now->subHour(), 'ends_at' => $this->now->setTime(10, 0), 'reason' => DowntimeReason::Breakdown, 'source' => Downtime::MANUAL]);
    });
    wallPlan($this);
    [, $token] = wallScreen($this);

    wallBoard($this, $token)->assertOk()
        ->assertJsonCount(2, 'stopped')
        ->assertJsonPath('stopped.0.planned', false)
        ->assertJsonPath('stopped.0.until', '10:00')
        ->assertJsonPath('stopped.1.planned', true)
        ->assertJsonPath('stopped.1.from', '13:00')
        ->assertJsonPath('stopped_more', 0);
});

it('names a wall screen, not a tablet, when a code is asked for one already paired', function () {
    [$screen] = wallScreen($this);

    expect(fn () => app(WorkshopDevices::class)->renewCode($this->owner, $this->company, $screen->fresh()))
        ->toThrow(ValidationException::class, 'Skärmen är redan kopplad. Lägg till en ny i stället.');
});

it('says there is no plan yet in a company that has not planned', function () {
    ['owner' => $this->owner, 'company' => $this->company] = Manufacturing::company();
    $this->actingAs($this->owner);
    [, $token] = wallScreen($this);

    wallBoard($this, $token)->assertOk()->assertJsonPath('run', null)->assertJsonPath('work', [])->assertJsonPath('late', [])->assertJsonPath('stopped', []);
});

it('answers 404 with the module off and 401 for a revoked or removed screen', function () {
    [$screen, $token] = wallScreen($this);

    app(ModuleManager::class)->disable(ManufacturingModule::KEY, $this->company, $this->owner, confirmed: true);
    wallBoard($this, $token)->assertNotFound()->assertJsonPath('error', 'module_off');
    app(ModuleManager::class)->enable(ManufacturingModule::KEY, $this->company, $this->owner);

    app(WorkshopDevices::class)->revoke($this->owner, $this->company, $screen);
    wallBoard($this, $token)->assertUnauthorized()->assertJsonPath('error', 'device_unknown');
    wallBoard($this, '')->assertUnauthorized();
});

it('writes when a screen was last seen at most every five minutes', function () {
    [$screen, $token] = wallScreen($this);

    wallBoard($this, $token)->assertOk();
    $first = WorkshopDevice::query()->find($screen->id)->last_seen_at;

    $this->travel(2)->minutes();
    wallBoard($this, $token)->assertOk();
    expect(WorkshopDevice::query()->find($screen->id)->last_seen_at->equalTo($first))->toBeTrue();

    $this->travel(4)->minutes();
    wallBoard($this, $token)->assertOk();
    expect(WorkshopDevice::query()->find($screen->id)->last_seen_at->greaterThan($first))->toBeTrue();
});

it('runs a fixed number of queries, whatever the number of orders', function () {
    Queue::fake();
    $article = $this->shop['orders'][1042]->article;
    [, $token] = wallScreen($this);
    $count = function () use ($token): int {
        CurrentCompany::forget();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($token)->getJson(route('manufacturing.wallScreen.board'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        CurrentCompany::set($this->company);

        return $queries;
    };

    foreach (range(1, 7) as $i) {
        PlanningWorkshop::order($this->company, $article, 3000 + $i, 1, [10, 10, 10, 10, 10], $this->shop['operations'], '2026-11-18', ProductionOrderStatus::Released);
    }

    wallPlan($this);
    $count();
    $ten = $count();

    foreach (range(8, 97) as $i) {
        PlanningWorkshop::order($this->company, $article, 3000 + $i, 1, [10, 10, 10, 10, 10], $this->shop['operations'], '2026-11-18', ProductionOrderStatus::Released);
    }

    wallPlan($this);

    expect($count())->toBe($ten);
    expect(count(app(WallBoard::class)->build($this->company)['work']))->toBeLessThanOrEqual(WallBoard::WORK_LIMIT);
});

it('lets a planner pair, renew, revoke and remove screens, and shows the pairing code once', function () {
    $page = Livewire::test(WallScreenAdminPage::class)
        ->set('deviceName', 'Tv:n i verkstaden')
        ->call('registerScreen')
        ->assertHasNoErrors();

    $pairing = $page->get('pairing');
    expect($pairing['url'])->toBe(route('manufacturing.wallScreen').'#kod='.$pairing['code'])
        ->and($pairing['url'])->not->toContain('?');
    $page->assertSee($pairing['code']);

    $screen = WorkshopDevice::query()->ofKind(DeviceKind::WallScreen)->sole();
    $page->call('renewCode', $screen->id)->assertHasNoErrors();
    expect($page->get('pairing')['code'])->not->toBe($pairing['code']);

    $page->call('revokeScreen', $screen->id);
    expect($screen->fresh()->revoked_at)->not->toBeNull();

    $page->call('removeScreen', $screen->id);
    expect(WorkshopDevice::query()->find($screen->id))->toBeNull();
});

it('keeps the terminal page and the wall screen page to their own devices', function () {
    Workshop::enable($this->company, $this->owner);
    [$tablet] = Workshop::device($this->owner, $this->company);
    [$screen] = wallScreen($this);

    Livewire::test(WallScreenAdminPage::class)->assertSee('Tv:n i verkstaden')->assertDontSee('Snickeriet');
    Livewire::test(TerminalAdminPage::class)->assertSee('Snickeriet')->assertDontSee('Tv:n i verkstaden');

    // Neither page acts on the other's devices.
    Livewire::test(WallScreenAdminPage::class)->call('revokeScreen', $tablet->id)->assertNotFound();
    Livewire::test(TerminalAdminPage::class)->call('revokeDevice', $screen->id)->assertNotFound();
    expect($tablet->fresh()->revoked_at)->toBeNull()->and($screen->fresh()->revoked_at)->toBeNull();
});

it('shows the preview to manufacturing:view and lets only production:plan set screens up', function () {
    wallPlan($this);

    $this->get(route('manufacturing.wallScreen.admin'))->assertOk()->assertSee('Det här visar skärmen aldrig');
    $this->get(route('manufacturing.wallScreen.preview'))->assertOk()->assertSee(route('manufacturing.wallScreen.preview.board', absolute: false));
    $this->getJson(route('manufacturing.wallScreen.preview.board'))->assertOk()->assertJsonPath('company', $this->company->name)->assertJsonPath('today', '2026-11-16');

    // An accountant sees Tillverkning but does not plan: the preview, not the setup.
    $this->actingAs(Bookkeeping::member($this->company, 'accountant'));
    $this->get(route('manufacturing.wallScreen.admin'))->assertOk()->assertDontSee('Lägg till skärm');
    $this->getJson(route('manufacturing.wallScreen.preview.board'))->assertOk();
    Livewire::test(WallScreenAdminPage::class)->set('deviceName', 'Tv')->call('registerScreen')->assertForbidden();

    // A bookkeeper has no manufacturing:view.
    $this->actingAs(Bookkeeping::member($this->company, 'bookkeeper'));
    $this->get(route('manufacturing.wallScreen.admin'))->assertForbidden();
    $this->get(route('manufacturing.wallScreen.preview'))->assertForbidden();
    $this->getJson(route('manufacturing.wallScreen.preview.board'))->assertForbidden();
});
