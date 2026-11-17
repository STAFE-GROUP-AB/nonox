<?php

declare(strict_types=1);

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Models\AuditLog;
use App\Models\CostCenter;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Planning\Actions\SaveArea;
use App\Modules\Planning\Actions\SaveForecastOverride;
use App\Modules\Planning\Actions\SaveHourProfile;
use App\Modules\Planning\Actions\SaveSalesSource;
use App\Modules\Planning\Actions\SaveSkill;
use App\Modules\Planning\Actions\SaveStaffingRule;
use App\Modules\Planning\Domain\Demand\DayDemand;
use App\Modules\Planning\Domain\Demand\Forecast;
use App\Modules\Planning\Domain\Demand\HourShape;
use App\Modules\Planning\Livewire\DemandPage;
use App\Modules\Planning\Livewire\SchedulePage;
use App\Modules\Planning\Livewire\StaffingPage;
use App\Modules\Planning\Models\ForecastOverride;
use App\Modules\Planning\Models\StaffingRule;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\Demand\DemandData;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosSale;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;

/*
 * Bemanningsbehov (ADR-037 amendment 2026-11-17, gap 1): the forecast from
 * the books, the shape of the day from a till or the company's own hour
 * profile, overrides, staffing targets, the demand against the shifts in
 * the day view and on Bemanning och kostnad, the settings page, tenancy,
 * and a fixed number of queries.
 */

beforeEach(function () {
    // Wednesday 18 November 2026.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Europe/Stockholm'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Planning::setUp($this->company, $this->owner);
    $this->actingAs($this->owner);
    $this->anna = Planning::employee($this->owner, $this->company, 'Anna', '900615123', ['salary_form' => 'HOURLY', 'salary' => '150']);
    $this->bo = Planning::employee($this->owner, $this->company, 'Bo', '850301234', ['salary_form' => 'HOURLY', 'salary' => '150']);
});

function demandLoad(object $test, string $from, string $to, mixed $area = null): array
{
    return app(DemandData::class)->load($test->company, CarbonImmutable::parse($from, 'Europe/Stockholm'), CarbonImmutable::parse($to, 'Europe/Stockholm'), $area);
}

/** Tuesday lunch 11–14: 1 000 kr per staffed hour, at least one person. */
function demandLunchRule(object $test, array $overrides = []): StaffingRule
{
    return app(SaveStaffingRule::class)($test->owner, $test->company, [
        'weekday' => 2, 'start' => '11:00', 'end' => '14:00', 'sales_per_staff_hour' => '1 000', 'min_staff' => 1, 'valid_from' => '2026-11-01', ...$overrides,
    ]);
}

/** Sales on the two Tuesdays before: 9 000 and 15 000 kr, so the forecast for a Tuesday is 12 000 kr. */
function demandTuesdaySales(object $test): void
{
    Bookkeeping::post($test->owner, $test->company, 900000, 1930, 3001, '2026-11-10');
    Bookkeeping::post($test->owner, $test->company, 1500000, 1930, 3001, '2026-11-03');
    // Not revenue.
    Bookkeeping::post($test->owner, $test->company, 500000, 1930, 2081, '2026-11-03');
}

function demandTill(object $test): PosConnection
{
    return CurrentCompany::run($test->company, fn () => PosConnection::create([
        'provider' => 'zettle', 'status' => 'ACTIVE', 'external_org_id' => 'demo-'.Str::random(8), 'display_name' => 'Kassan', 'credentials' => [],
        'webhook_token' => Str::random(48), 'settings' => [], 'connected_by' => $test->owner->id, 'connected_at' => now(),
    ]));
}

function demandReceipt(object $test, PosConnection $till, string $at, int $total, int $vat, int $tips = 0, bool $refund = false): void
{
    CurrentCompany::run($test->company, fn () => PosSale::create([
        'pos_connection_id' => $till->id, 'provider' => 'zettle', 'external_id' => (string) Str::uuid(), 'source' => 'WEBHOOK', 'is_refund' => $refund,
        'sold_at' => CarbonImmutable::parse($at, 'Europe/Stockholm'), 'business_date' => substr($at, 0, 10), 'currency' => 'SEK',
        'total_ore' => $total, 'vat_ore' => $vat, 'tips_ore' => $tips, 'vat_totals' => [], 'payments' => [], 'payload' => [], 'payload_hash' => str_repeat('b', 64),
    ]));
}

it('forecasts from the books, spreads by the own hour profile and puts the need against the shifts', function () {
    demandTuesdaySales($this);
    demandLunchRule($this);
    // Own estimate for Tuesdays: half at 12, a quarter each at 11 and 13.
    app(SaveHourProfile::class)($this->owner, $this->company, null, 2, [11 => '25', 12 => '50', 13 => '25']);
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-24', 'start' => '10:00', 'end' => '15:00']);
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->bo->id, 'date' => '2026-11-24', 'start' => '12:00', 'end' => '14:00']);
    Planning::shift($this->owner, $this->company, ['date' => '2026-11-24', 'start' => '12:00', 'end' => '13:00']);

    $day = demandLoad($this, '2026-11-24', '2026-11-24')['days']['2026-11-24'];

    // 12 000 kr: 3 000 at 11 (3 people), 6 000 at 12 (6), 3 000 at 13 (3).
    expect($day->forecast->kind)->toBe(Forecast::AVERAGE)
        ->and($day->forecast->amountOre)->toBe(1_200_000)
        ->and($day->forecast->basis)->toBe(['2026-11-10', '2026-11-03'])
        ->and($day->shape)->toBe(HourShape::PROFILE)
        ->and($day->hours[11])->toMatchArray(['sales' => 300_000, 'need' => 3, 'planned' => 1.0, 'status' => DayDemand::UNDER])
        ->and($day->hours[12])->toMatchArray(['sales' => 600_000, 'need' => 6, 'planned' => 2.0, 'open' => 1.0, 'status' => DayDemand::UNDER])
        ->and($day->hours[10]['need'])->toBeNull()
        ->and($day->needMinutes)->toBe(12 * 60)
        // Inside the target's 11–14 only: Anna 3 h, Bo 2 h; her 10–11 and 14–15 count for neither.
        ->and($day->plannedMinutes)->toBe(5 * 60)
        ->and($day->status)->toBe(DayDemand::UNDER);

    // Without the profile the day is per day only: ceil(12 000 ÷ 1 000) = 12 staff-hours.
    app(SaveHourProfile::class)($this->owner, $this->company, null, 2, []);
    $perDay = demandLoad($this, '2026-11-24', '2026-11-24')['days']['2026-11-24'];

    expect($perDay->shape)->toBeNull()
        ->and($perDay->hours[12]['sales'])->toBeNull()
        ->and($perDay->hours[12]['need'])->toBe(1)
        ->and($perDay->needMinutes)->toBe(12 * 60);
});

it('takes the shape of the day from the till, net of VAT and tips, refunds negative, and the total from the books', function () {
    demandTuesdaySales($this);
    demandLunchRule($this);
    $till = demandTill($this);
    // Tuesday 10 November: 1 000 kr net at 11, 3 000 kr at 12 (tips apart), a 500 kr refund at 12; Tuesday 3 November: 1 000 kr at 13.
    demandReceipt($this, $till, '2026-11-10 11:15', 125000, 25000);
    demandReceipt($this, $till, '2026-11-10 12:40', 375000, 75000, tips: 20000);
    demandReceipt($this, $till, '2026-11-10 12:50', -62500, -12500, refund: true);
    demandReceipt($this, $till, '2026-11-03 13:05', 125000, 25000);

    // Not mapped yet: no shape.
    expect(demandLoad($this, '2026-11-24', '2026-11-24')['days']['2026-11-24']->shape)->toBeNull();

    app(SaveSalesSource::class)($this->owner, $this->company, $till->id, SaveSalesSource::COMPANY);
    $data = demandLoad($this, '2026-11-24', '2026-11-24');
    $day = $data['days']['2026-11-24'];

    // Weights 1 000 / 2 500 / 1 000 of 4 500: 12 000 kr splits 2 667 / 6 666 / 2 667.
    expect($data['till'])->toBeTrue()
        ->and($day->shape)->toBe(HourShape::TILL)
        ->and($day->forecast->amountOre)->toBe(1_200_000)
        ->and($day->hours[11]['sales'] + $day->hours[12]['sales'] + $day->hours[13]['sales'])->toBe(1_200_000)
        ->and([$day->hours[11]['sales'], $day->hours[12]['sales'], $day->hours[13]['sales']])->toBe([266_667, 666_667, 266_666])
        ->and($day->hours[12]['need'])->toBe(7);

    app(SaveSalesSource::class)($this->owner, $this->company, $till->id, SaveSalesSource::OFF);
    expect(demandLoad($this, '2026-11-24', '2026-11-24')['till'])->toBeFalse();
});

it('reads an area\'s sales on its cost centre once the area has one, and the whole company\'s until then', function () {
    $centre = CurrentCompany::run($this->company, fn () => CostCenter::create(['code' => 'KOK', 'name' => 'Kök']));
    $kitchen = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Kök', 'colour' => '#e4572e']);
    app(PostVoucher::class)($this->owner, app(SaveDraftVoucher::class)($this->owner, $this->company, [
        'voucher_date' => '2026-11-10', 'description' => 'Dagskassa', 'series' => 'A',
        'rows' => [
            ['account' => 1930, 'debit_ore' => 1_000_000, 'credit_ore' => 0],
            ['account' => 3001, 'debit_ore' => 0, 'credit_ore' => 400_000, 'cost_center' => 'KOK'],
            ['account' => 3001, 'debit_ore' => 0, 'credit_ore' => 600_000],
        ],
    ]));

    $whole = demandLoad($this, '2026-11-17', '2026-11-17', $kitchen);
    expect($whole['sales_scope'])->toBe('company')
        ->and($whole['days']['2026-11-17']->forecast->amountOre)->toBe(1_000_000);

    // What package P2 adds: planning_areas.cost_center_id. Set in memory here, read by AreaCostCentre.
    $kitchen->setAttribute('cost_center_id', $centre->id);
    $own = demandLoad($this, '2026-11-17', '2026-11-17', $kitchen);

    expect($own['sales_scope'])->toBe('area')
        ->and($own['days']['2026-11-17']->forecast->amountOre)->toBe(400_000);
});

it('replaces a day with an override and keeps the reason out of the audit log', function () {
    demandTuesdaySales($this);
    $save = app(SaveForecastOverride::class);
    $save($this->owner, $this->company, ['date' => '2026-11-24', 'amount' => '30 000', 'reason' => 'Konsert i parken']);
    // Saving again replaces it.
    $override = $save($this->owner, $this->company, ['date' => '2026-11-24', 'amount' => '35 000,50', 'reason' => 'Konsert i parken, slutsålt']);

    $day = demandLoad($this, '2026-11-24', '2026-11-24')['days']['2026-11-24'];

    expect(CurrentCompany::run($this->company, fn () => ForecastOverride::query()->count()))->toBe(1)
        ->and($day->forecast->isOverride())->toBeTrue()
        ->and($day->forecast->amountOre)->toBe(3_500_050)
        ->and($day->forecast->computedOre)->toBe(1_200_000)
        ->and($day->forecast->reason)->toBe('Konsert i parken, slutsålt');

    $logs = CurrentCompany::run($this->company, fn () => AuditLog::query()->where('action', 'like', 'planning.forecast_override%')->get());
    expect($logs)->toHaveCount(2)
        ->and(json_encode($logs->pluck('payload')->all()))->not->toContain('Konsert');

    $save->delete($this->owner, $this->company, $override);
    expect(demandLoad($this, '2026-11-24', '2026-11-24')['days']['2026-11-24']->forecast->kind)->toBe(Forecast::AVERAGE);
});

it('validates targets, profiles and overrides in Swedish', function () {
    $rule = app(SaveStaffingRule::class);
    $fails = function (callable $call, string $key): void {
        try {
            $call();
            test()->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($key);
        }
    };

    $fails(fn () => $rule($this->owner, $this->company, ['start' => '11:00', 'end' => '14:00', 'valid_from' => '2026-11-01']), 'sales_per_staff_hour_ore');
    $fails(fn () => $rule($this->owner, $this->company, ['start' => '22:00', 'end' => '02:00', 'min_staff' => 2, 'valid_from' => '2026-11-01']), 'end_minute');
    $fails(fn () => $rule($this->owner, $this->company, ['start' => '11:00', 'end' => '14:00', 'sales_per_staff_hour' => 'mycket', 'valid_from' => '2026-11-01']), 'sales_per_staff_hour_ore');
    $fails(fn () => app(SaveHourProfile::class)($this->owner, $this->company, null, 2, [11 => '50', 12 => '40']), 'percents');
    $fails(fn () => app(SaveForecastOverride::class)($this->owner, $this->company, ['date' => '2026-11-24', 'amount' => '1 000', 'reason' => '']), 'reason');

    // "24:00" is the end of the day.
    $late = $rule($this->owner, $this->company, ['start' => '18:00', 'end' => '24:00', 'min_staff' => 2, 'valid_from' => '2026-11-01']);
    expect($late->end_minute)->toBe(1440);
});

it('lets planners change demand and everyone with planning:view see it', function () {
    $scheduler = Bookkeeping::member($this->company, 'scheduler');
    $accountant = Bookkeeping::member($this->company, 'accountant');

    expect(demandLunchRule((object) ['owner' => $scheduler, 'company' => $this->company]))->toBeInstanceOf(StaffingRule::class);
    expect(fn () => demandLunchRule((object) ['owner' => $accountant, 'company' => $this->company]))->toThrow(AuthorizationException::class);

    $this->actingAs($accountant);
    Livewire::test(DemandPage::class)->assertForbidden();

    $this->actingAs($this->owner);
    $this->get(route('planning.demand'))->assertOk()->assertSee('Bemanningsbehov');
    app(ModuleManager::class)->disable(PlanningModule::KEY, $this->company, $this->owner, confirmed: true);
    $this->get('/planering/bemanningsbehov')->assertNotFound();
});

it('edits targets, overrides, the hour profile, tills and weeks on the page, with toasts', function () {
    demandTuesdaySales($this);
    $till = demandTill($this);
    $kitchen = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Kök', 'colour' => '#e4572e']);

    $page = Livewire::test(DemandPage::class)
        ->assertSee('Prognos de kommande två veckorna')
        ->assertSee('Inga bemanningsmål ännu')
        ->call('createRule')
        ->set('rule.weekday', '2')
        ->set('rule.start', '11:00')
        ->set('rule.end', '14:00')
        ->set('rule.sales_per_staff_hour', '1 000')
        ->call('saveRule')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Bemanningsmålet är tillagt.')
        ->set('override.date', '2026-11-24')
        ->set('override.amount', '20 000')
        ->set('override.reason', 'Mässa i stan')
        ->call('saveOverride')
        ->assertDispatched('toast', message: 'Prognosen för dagen är ändrad.')
        ->set('profileDay', 2)
        ->set('percents.12', '100')
        ->call('saveProfile')
        ->assertDispatched('toast', message: 'Timprofilen är sparad.')
        ->set("sources.{$till->id}", (string) $kitchen->id)
        ->call('saveSource', $till->id)
        ->assertDispatched('toast', message: 'Kassan är sparad.')
        ->set('weeks', '8')
        ->call('saveWeeks')
        ->assertDispatched('toast', message: 'Prognosen räknar nu på 8 veckor.');

    $page->assertSee('Mässa i stan')->assertSee('ändrad');
    $page->set('weeks', '40')->call('saveWeeks')->assertHasErrors('weeks');

    expect(PlanningSettings::int(PlanningSettings::resolve(app(ModuleManager::class)->settings(PlanningModule::KEY, $this->company)), 'forecast_weeks'))->toBe(8)
        ->and(CurrentCompany::run($this->company, fn () => StaffingRule::query()->sole()->sales_per_staff_hour_ore))->toBe(100_000);

    $rule = CurrentCompany::run($this->company, fn () => StaffingRule::query()->sole());
    $page->call('editRule', $rule->id)->set('rule.min_staff', '2')->call('saveRule')->assertDispatched('toast', message: 'Bemanningsmålet är sparat.');
    $page->call('deleteRule', $rule->id)->assertDispatched('toast', message: 'Bemanningsmålet är borttaget.');

    // The area is its own scope: none of the whole company's targets.
    Livewire::withQueryParams(['avdelning' => $kitchen->id])->test(DemandPage::class)->assertSee('Bemanningsmål för Kök');
});

it('answers 404 for another company\'s targets, overrides, tills and areas', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    // Tax tables are per installation and already imported for A.
    collect([PayrollModule::KEY, PlanningModule::KEY])->each(fn (string $key) => app(ModuleManager::class)->enable($key, $other, $otherOwner));
    $foreignRule = demandLunchRule((object) ['owner' => $otherOwner, 'company' => $other]);
    $foreignOverride = app(SaveForecastOverride::class)($otherOwner, $other, ['date' => '2026-11-24', 'amount' => '1 000', 'reason' => 'Hemlig']);
    $foreignTill = demandTill((object) ['owner' => $otherOwner, 'company' => $other]);
    $foreignArea = app(SaveArea::class)($otherOwner, $other, ['name' => 'Hemligt', 'colour' => '#e4572e']);

    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    Livewire::test(DemandPage::class)->call('editRule', $foreignRule->id)->assertNotFound();
    Livewire::test(DemandPage::class)->call('deleteRule', $foreignRule->id)->assertNotFound();
    Livewire::test(DemandPage::class)->call('deleteOverride', $foreignOverride->id)->assertNotFound();
    Livewire::test(DemandPage::class)->set("sources.{$foreignTill->id}", 'company')->call('saveSource', $foreignTill->id)->assertNotFound();
    Livewire::withQueryParams(['avdelning' => $foreignArea->id])->test(DemandPage::class)->assertNotFound();

    expect(fn () => app(SaveStaffingRule::class)($this->owner, $this->company, ['area_id' => $foreignArea->id, 'start' => '11:00', 'end' => '14:00', 'min_staff' => 1, 'valid_from' => '2026-11-01']))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveSalesSource::class)($this->owner, $this->company, $foreignTill->id, 'company'))->toThrow(ModelNotFoundException::class);

    // A's demand never reads B's targets or overrides.
    expect(demandLoad($this, '2026-11-24', '2026-11-24')['days']['2026-11-24']->hasRules)->toBeFalse();
});

it('creates areas and skills in the company given, whatever company is current, and refuses another company\'s', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    collect([PayrollModule::KEY, PlanningModule::KEY])->each(fn (string $key) => app(ModuleManager::class)->enable($key, $other, $otherOwner));
    CurrentCompany::set($this->company);

    $area = app(SaveArea::class)($otherOwner, $other, ['name' => 'Bar', 'colour' => '#e4572e']);
    $skill = app(SaveSkill::class)($otherOwner, $other, 'Kassa');

    expect($area->company_id)->toBe($other->id)
        ->and($skill->company_id)->toBe($other->id);

    // A's owner, acting for A, cannot change, archive or delete B's.
    expect(fn () => app(SaveArea::class)($this->owner, $this->company, ['name' => 'X', 'colour' => '#e4572e'], $area))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(SaveArea::class)->archive($this->owner, $this->company, $area))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(SaveSkill::class)($this->owner, $this->company, 'Y', $skill))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(SaveSkill::class)->delete($this->owner, $this->company, $skill))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(SaveSkill::class)->assign($this->owner, $this->company, CurrentCompany::run($other, fn () => Planning::employee($otherOwner, $other, 'Cia', '880101234')), []))->toThrow(NotFoundHttpException::class);

    // Archiving in B while A is current still archives B's area.
    expect(app(SaveArea::class)->archive($otherOwner, $other, $area)->archived_at)->not->toBeNull();
});

it('warns when the books hold sales in lumps rather than per day', function () {
    // One week averaged: the look-back is 4–17 November, ten weekdays. Sales on Mondays only: 2 of 10.
    app(SaveSalesSource::class)->weeks($this->owner, $this->company, 1);

    foreach (['2026-11-16', '2026-11-09', '2026-11-02'] as $monday) {
        Bookkeeping::post($this->owner, $this->company, 5000000, 1930, 3001, $monday);
    }

    expect(demandLoad($this, '2026-11-24', '2026-11-24')['lumped'])->toBeTrue();
    Livewire::test(DemandPage::class)->assertSee('Bokför försäljningen per dag, till exempel dagskassan, för att prognosen ska stämma.');

    demandTuesdaySales($this);
    foreach (['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-17'] as $day) {
        Bookkeeping::post($this->owner, $this->company, 100000, 1930, 3001, $day);
    }

    expect(demandLoad($this, '2026-11-24', '2026-11-24')['lumped'])->toBeFalse();
});

it('shows the band in the day view, the need in the week footer and on Bemanning och kostnad', function () {
    demandTuesdaySales($this);
    demandLunchRule($this);
    app(SaveHourProfile::class)($this->owner, $this->company, null, 2, [11 => '25', 12 => '50', 13 => '25']);
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-24', 'start' => '10:00', 'end' => '15:00']);

    Livewire::withQueryParams(['vy' => 'dag', 'dag' => '2026-11-24'])->test(SchedulePage::class)
        ->assertSee('Behov per timme')
        ->assertSee('Snitt av samma veckodag 2 veckor: 10/11, 3/11.')
        ->assertSee('Fördelning per timme: egen uppskattning')
        ->assertSee('Underbemannat kl. 11–14.')
        ->assertSee('1/6');

    Livewire::withQueryParams(['vecka' => '2026-11-23'])->test(SchedulePage::class)
        ->assertSee('Behov')
        ->assertSee('12 h');

    Livewire::withQueryParams(['fran' => '2026-11-23', 'till' => '2026-11-29'])->test(StaffingPage::class)
        ->assertSee('Prognos')
        ->assertSee('12 h ▲')
        ->assertSee('Bemanningsbehov');

    // With an area chosen, the area's own targets: none here.
    $kitchen = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Kök', 'colour' => '#e4572e']);
    Livewire::withQueryParams(['fran' => '2026-11-23', 'till' => '2026-11-29', 'avdelning' => $kitchen->id])->test(StaffingPage::class)
        ->assertSee('Inga bemanningsmål finns ännu, så behovet visas inte.')
        ->assertSeeHtml('bemanningsbehov?avdelning='.$kitchen->id);
});

it('loads demand in the same number of queries for a short and a long history', function () {
    $count = function (string $from, string $to): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        demandLoad($this, $from, $to);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // The settings page as well, which shows the next two weeks.
    $page = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(DemandPage::class);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    demandLunchRule($this);
    $till = demandTill($this);
    app(SaveSalesSource::class)($this->owner, $this->company, $till->id, SaveSalesSource::COMPANY);
    $count('2026-11-23', '2026-11-29');
    $few = $count('2026-11-23', '2026-11-29');
    $page();
    $fewPage = $page();

    foreach (range(1, 8) as $week) {
        $tuesday = CarbonImmutable::parse('2026-11-17', 'Europe/Stockholm')->subWeeks($week);
        Bookkeeping::post($this->owner, $this->company, 100000 * $week, 1930, 3001, $tuesday->toDateString());
        demandReceipt($this, $till, $tuesday->toDateString().' 12:00', 125000, 25000);
    }

    foreach (range(0, 27) as $offset) {
        $day = CarbonImmutable::parse('2026-11-23', 'Europe/Stockholm')->addDays($offset);
        Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => $day->toDateString(), 'start' => '10:00', 'end' => '15:00']);
        app(SaveForecastOverride::class)($this->owner, $this->company, ['date' => $day->toDateString(), 'amount' => '5 000', 'reason' => 'Test']);
        demandLunchRule($this, ['weekday' => $day->isoWeekday(), 'valid_from' => $day->toDateString()]);
    }

    app(SaveHourProfile::class)($this->owner, $this->company, null, 2, [12 => '100']);

    expect($count('2026-11-23', '2026-12-20'))->toBe($few)
        ->and($page())->toBe($fewPage);
});
