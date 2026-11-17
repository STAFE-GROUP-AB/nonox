<?php

declare(strict_types=1);

use App\Domain\Firm\ChainSchedule;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Planning\Actions\SaveArea;
use App\Modules\Planning\Actions\SaveBudget;
use App\Modules\Planning\Domain\Budget\BudgetLine;
use App\Modules\Planning\Domain\Budget\BudgetWeek;
use App\Modules\Planning\Livewire\BudgetPage;
use App\Modules\Planning\Livewire\SchedulePage;
use App\Modules\Planning\Models\Budget;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\Budget\BudgetData;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;

/*
 * Budget för personalkostnad (ADR-037 amendment 2026-11-17, gap 2): budgets
 * per week and area or the whole company, an amount or a share of the
 * forecast, against the shifts' estimated cost; warnings at the threshold
 * and at 100 % in the schedule's footer and the toasts, never blocking;
 * planning:budget to set them; the chain view read only; tenancy and a
 * fixed number of queries.
 */

beforeEach(function () {
    // Wednesday 18 November 2026; the week planned is week 48, 23–29 November.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Europe/Stockholm'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Planning::setUp($this->company, $this->owner);
    $this->actingAs($this->owner);
    $this->anna = Planning::employee($this->owner, $this->company, 'Anna', '900615123', ['salary_form' => 'HOURLY', 'salary' => '150']);
    $this->monday = CarbonImmutable::parse('2026-11-23', 'Europe/Stockholm');
});

function p6Week(object $test, ?Company $company = null): BudgetWeek
{
    return app(BudgetData::class)->forCompany($company ?? $test->company, [$test->monday])['2026-11-23'];
}

function p6Shift(object $test, string $date = '2026-11-24', string $start = '10:00', string $end = '15:00', ?int $area = null): Shift
{
    return Planning::shift($test->owner, $test->company, ['employee_id' => $test->anna->id, 'date' => $date, 'start' => $start, 'end' => $end, 'area_id' => $area]);
}

/** @param  array<string, mixed>  $input */
function p6Budget(object $test, array $input): int
{
    return app(SaveBudget::class)($test->owner, $test->company, ['from' => '2026-11-23', ...$input]);
}

function p6Kronor(int $ore): string
{
    return (string) intdiv($ore, 100);
}

it('compares a week\'s amount with the estimated cost and warns at the threshold and at 100 %', function () {
    $shift = p6Shift($this);
    $cost = (int) $shift->cost_estimate_ore;
    expect($cost)->toBeGreaterThan(0);

    // Twice the cost: inside the budget.
    p6Budget($this, ['kind' => 'amount', 'value' => p6Kronor($cost * 2)]);
    $line = p6Week($this)->company;
    expect($line->kind)->toBe(BudgetLine::AMOUNT)
        ->and($line->costOre)->toBe($cost)
        ->and($line->status())->toBe(BudgetLine::OK);

    // A budget the cost is 96 % of: at the 95 % threshold.
    p6Budget($this, ['kind' => 'amount', 'value' => (string) round($cost / 0.96 / 100)]);
    expect(p6Week($this)->company->status())->toBe(BudgetLine::NEAR);

    // A raised threshold (97 %) takes the warning away.
    app(SaveBudget::class)->warnAt($this->owner, $this->company, '97');
    expect(p6Week($this)->company->status())->toBe(BudgetLine::OK)
        ->and(PlanningSettings::budgetWarnAt(PlanningSettings::resolve(app(ModuleManager::class)->settings(PlanningModule::KEY, $this->company))))->toBe(9700);

    // Half the cost: over.
    p6Budget($this, ['kind' => 'amount', 'value' => p6Kronor(intdiv($cost, 2))]);
    $line = p6Week($this)->company;
    expect($line->status())->toBe(BudgetLine::OVER)
        ->and($line->exceeded())->toBeTrue()
        ->and($line->usedPercent())->toBeGreaterThan(190.0);

    Livewire::withQueryParams(['vecka' => '2026-11-23'])->test(SchedulePage::class)
        ->assertSee('Budget vecka 48')
        ->assertSee('Hela företaget')
        ->assertSee('är över budgeten')
        ->assertSee('Budgeten varnar men hindrar aldrig planeringen.');
});

it('takes a share of the week\'s forecast from Bemanningsbehov, and compares only the days that have one', function () {
    // Before any sales: the week has no forecast, so the share has no amount and never warns.
    $tuesday = p6Shift($this);
    p6Budget($this, ['kind' => 'percent', 'value' => '25']);
    $empty = p6Week($this)->company;
    expect($empty->budgetOre)->toBeNull()
        ->and($empty->forecastDays)->toBe(0)
        ->and($empty->status())->toBe(BudgetLine::UNKNOWN)
        ->and($empty->warns())->toBeFalse();

    // Two Tuesdays before: 9 000 and 15 000 kr, so Tuesday 24 November is forecast at 12 000 kr; no other day has sales.
    Bookkeeping::post($this->owner, $this->company, 900000, 1930, 3001, '2026-11-10');
    Bookkeeping::post($this->owner, $this->company, 1500000, 1930, 3001, '2026-11-03');

    $line = p6Week($this)->company;

    // Six days without a forecast and without shifts (closed): no gap, no note.
    expect($line->kind)->toBe(BudgetLine::PERCENT)
        ->and($line->percentBp)->toBe(2500)
        ->and($line->forecastOre)->toBe(1_200_000)
        ->and($line->forecastDays)->toBe(1)
        ->and($line->forecastDates)->toBe(['2026-11-24'])
        ->and($line->budgetOre)->toBe(300_000)
        ->and($line->costOre)->toBe($tuesday->cost_estimate_ore)
        ->and($line->uncoveredDays)->toBe(0);

    // A shift on Wednesday, which has no forecast: its cost is left out and named, so it cannot make the week look over budget.
    $wednesday = p6Shift($this, '2026-11-25');
    $line = p6Week($this)->company;
    expect($line->costOre)->toBe($tuesday->cost_estimate_ore)
        ->and($line->uncoveredDays)->toBe(1)
        ->and($line->uncoveredOre)->toBe($wednesday->cost_estimate_ore)
        ->and($line->counts(null, '2026-11-25'))->toBeFalse()
        ->and($line->counts(null, '2026-11-24'))->toBeTrue();

    $note = 'Passen 1 dag utan prognos ('.Money::ofOre((int) $wednesday->cost_estimate_ore)->format().') räknas inte mot budgeten.';
    Livewire::withQueryParams(['vecka' => '2026-11-23'])->test(BudgetPage::class)
        ->assertSee('25 % av prognosen')
        ->assertSee($note);
    Livewire::withQueryParams(['vecka' => '2026-11-23'])->test(SchedulePage::class)
        ->assertSee('Hela företaget: '.$note);
});

it('keeps budgets per area and sums the areas when the whole company has none', function () {
    $kitchen = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Kök', 'colour' => '#e4572e']);
    $bar = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Bar', 'colour' => '#2e86e4']);
    $inKitchen = p6Shift($this, area: $kitchen->id);
    $inBar = p6Shift($this, '2026-11-25', area: $bar->id);
    p6Shift($this, '2026-11-26');

    p6Budget($this, ['area_id' => $kitchen->id, 'kind' => 'amount', 'value' => '100 000']);
    p6Budget($this, ['area_id' => $bar->id, 'kind' => 'amount', 'value' => '50 000']);
    $week = p6Week($this);

    expect($week->company)->toBeNull()
        ->and($week->areas[$kitchen->id]->costOre)->toBe($inKitchen->cost_estimate_ore)
        ->and($week->areas[$bar->id]->costOre)->toBe($inBar->cost_estimate_ore)
        ->and($week->total()->kind)->toBe(BudgetLine::AREAS)
        ->and($week->total()->budgetOre)->toBe(15_000_000)
        // The shift without an area is not in the areas' sum.
        ->and($week->total()->costOre)->toBe($inKitchen->cost_estimate_ore + $inBar->cost_estimate_ore)
        ->and($week->total()->covers(null))->toBeFalse()
        ->and($week->total()->covers($kitchen->id))->toBeTrue();

    Livewire::withQueryParams(['vecka' => '2026-11-23'])->test(BudgetPage::class)
        ->assertSee('Summan av avdelningarnas budgetar');
    Livewire::withQueryParams(['vecka' => '2026-11-23', 'avdelning' => $kitchen->id])->test(BudgetPage::class)
        ->assertSee('Budget och beräknad kostnad för Kök')
        ->assertSee(Money::ofOre(10_000_000)->format());
});

it('warns in the toast when a save raises the cost over the budget, never blocks, and is quiet when the cost falls', function () {
    $first = p6Shift($this);
    p6Budget($this, ['kind' => 'amount', 'value' => p6Kronor((int) $first->cost_estimate_ore)]);

    // A second shift the same week takes the cost over: saved anyway, with the warning.
    Livewire::withQueryParams(['vecka' => '2026-11-23'])->test(SchedulePage::class)
        ->call('create', $this->anna->id, '2026-11-25')
        ->set('form.start', '10:00')
        ->set('form.end', '14:00')
        ->call('save')
        ->assertDispatched('toast', fn (string $name, array $params): bool => str_contains($params['message'] ?? '', 'Vecka 48, Hela företaget: den beräknade kostnaden'));

    expect(CurrentCompany::run($this->company, fn () => Shift::query()->count()))->toBe(2);

    // Shortening a shift lowers the cost: no budget warning.
    $second = CurrentCompany::run($this->company, fn () => Shift::query()->whereKeyNot($first->id)->firstOrFail());
    Livewire::withQueryParams(['vecka' => '2026-11-23'])->test(SchedulePage::class)
        ->call('moveShift', $second->id, $this->anna->id, '2026-11-25T10:00:00+01:00', '2026-11-25T12:00:00+01:00')
        ->assertDispatched('toast', fn (string $name, array $params): bool => ! str_contains($params['message'] ?? '', 'budgeten'));

    // Publishing a week over its budget says so.
    Livewire::withQueryParams(['vecka' => '2026-11-23'])->test(SchedulePage::class)
        ->call('openPublish')
        ->call('publish')
        ->assertDispatched('toast', fn (string $name, array $params): bool => str_contains($params['message'] ?? '', 'är över budgeten'));
});

it('sets a period from one amount, copies a week forward without overwriting, and removes a week', function () {
    $save = app(SaveBudget::class);

    expect($save($this->owner, $this->company, ['from' => '2026-11-25', 'to' => '2026-12-16', 'kind' => 'amount', 'value' => '20 000']))->toBe(4);
    $weeks = CurrentCompany::run($this->company, fn () => Budget::query()->orderBy('week_start')->get());
    // Any day of a week means that week's Monday.
    expect($weeks->map(fn (Budget $budget): string => $budget->week_start->toDateString())->all())->toBe(['2026-11-23', '2026-11-30', '2026-12-07', '2026-12-14'])
        ->and($weeks->every(fn (Budget $budget): bool => $budget->amount_ore === 2_000_000))->toBeTrue();

    // Saving again replaces; a percentage replaces an amount.
    $save($this->owner, $this->company, ['from' => '2026-11-30', 'kind' => 'percent', 'value' => '28,5']);
    $changed = CurrentCompany::run($this->company, fn () => Budget::query()->whereDate('week_start', '2026-11-30')->firstOrFail());
    expect($changed->percent_bp)->toBe(2850)->and($changed->amount_ore)->toBeNull();

    // Copying week 51 forward three weeks: the first two are new.
    $result = $save->copyForward($this->owner, $this->company, '2026-12-14', 3);
    expect($result)->toBe(['copied' => 3, 'kept' => 0]);
    expect($save->copyForward($this->owner, $this->company, '2026-11-23', 2))->toBe(['copied' => 0, 'kept' => 2]);

    $save->delete($this->owner, $this->company, $changed);
    expect(CurrentCompany::run($this->company, fn () => Budget::query()->count()))->toBe(6);

    // The audit log holds ids, enums and counts, never amounts.
    $logs = CurrentCompany::run($this->company, fn () => AuditLog::query()->where('action', 'like', 'planning.budget%')->get());
    expect($logs->pluck('action')->unique()->values()->all())->toBe(['planning.budget_saved', 'planning.budget_copied', 'planning.budget_deleted'])
        ->and(json_encode($logs->pluck('payload')->all()))->not->toContain('2000000');
});

it('validates budgets in Swedish', function () {
    $save = app(SaveBudget::class);
    $fails = function (callable $call, string $key): void {
        try {
            $call();
            test()->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($key);
        }
    };

    $fails(fn () => $save($this->owner, $this->company, ['from' => '', 'kind' => 'amount', 'value' => '1 000']), 'from');
    $fails(fn () => $save($this->owner, $this->company, ['from' => '2026-11-23', 'kind' => 'amount', 'value' => 'mycket']), 'amount_ore');
    $fails(fn () => $save($this->owner, $this->company, ['from' => '2026-11-23', 'kind' => 'percent', 'value' => '']), 'percent_bp');
    $fails(fn () => $save($this->owner, $this->company, ['from' => '2026-11-23', 'kind' => 'percent', 'value' => '150']), 'percent_bp');
    $fails(fn () => $save($this->owner, $this->company, ['from' => '2026-11-30', 'to' => '2026-11-23', 'kind' => 'amount', 'value' => '1 000']), 'to');
    $fails(fn () => $save($this->owner, $this->company, ['from' => '2026-01-05', 'to' => '2027-06-28', 'kind' => 'amount', 'value' => '1 000']), 'to');
    $fails(fn () => $save->copyForward($this->owner, $this->company, '2026-11-23', 0), 'times');
    $fails(fn () => $save->warnAt($this->owner, $this->company, '40'), 'budget_warn_at_bp');

    expect(SaveBudget::basisPoints('28,5 %'))->toBe(2850)
        ->and(SaveBudget::basisPoints('30'))->toBe(3000)
        ->and(SaveBudget::basisPoints('0,05'))->toBe(5)
        ->and(SaveBudget::basisPoints('abc'))->toBeNull();
});

it('lets owners, admins and payroll admins set the budget, and the scheduler only see it', function () {
    $scheduler = Bookkeeping::member($this->company, 'scheduler');
    $payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin');
    $accountant = Bookkeeping::member($this->company, 'accountant');
    p6Shift($this);

    expect(app(SaveBudget::class)($payrollAdmin, $this->company, ['from' => '2026-11-23', 'kind' => 'amount', 'value' => '1 000']))->toBe(1)
        ->and(fn () => app(SaveBudget::class)($scheduler, $this->company, ['from' => '2026-11-23', 'kind' => 'amount', 'value' => '1 000']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveBudget::class)->warnAt($scheduler, $this->company, '90'))->toThrow(AuthorizationException::class);

    $this->actingAs($scheduler);
    Livewire::withQueryParams(['vecka' => '2026-11-23'])->test(BudgetPage::class)
        ->assertSee('Budget och beräknad kostnad för Hela företaget')
        ->assertDontSee('Spara budget')
        ->assertSee('Budgeten sätts av ägare, administratörer och löneadministratörer.')
        ->call('editWeek', '2026-11-23')
        ->assertForbidden();
    Livewire::withQueryParams(['vecka' => '2026-11-23'])->test(SchedulePage::class)
        ->assertSee('Budget vecka 48')
        ->assertSee('Visa budget');

    $this->actingAs($accountant);
    Livewire::test(BudgetPage::class)->assertForbidden();

    $this->actingAs($this->owner);
    $this->get(route('planning.budget'))->assertOk()->assertSee('Budget för personalkostnad');
    app(ModuleManager::class)->disable(PlanningModule::KEY, $this->company, $this->owner, confirmed: true);
    $this->get('/planering/budget')->assertNotFound();
    expect($this->owner->fresh()->hasTeamPermission($this->company, 'planning:budget'))->toBeFalse();
});

it('edits, saves, copies and removes on the page, with toasts', function () {
    Livewire::withQueryParams(['vecka' => '2026-11-23'])->test(BudgetPage::class)
        ->assertSee('Ingen budget')
        ->set('form.from', '2026-11-23')
        ->set('form.to', '2026-11-30')
        ->set('form.kind', 'amount')
        ->set('form.value', '30 000')
        ->call('save')
        ->assertDispatched('toast', fn (string $name, array $params): bool => ($params['message'] ?? '') === 'Budgeten är sparad för 2 veckor.')
        ->assertSee(Money::ofOre(3_000_000)->format())
        ->call('editWeek', '2026-11-23')
        ->assertSet('form.value', Money::ofOre(3_000_000)->format(false))
        ->set('copy.week', '2026-11-30')
        ->set('copy.times', '2')
        ->call('copyForward')
        ->assertDispatched('toast', fn (string $name, array $params): bool => ($params['message'] ?? '') === '2 veckobudgetar är kopierade.')
        ->set('warnAt', '90')
        ->call('saveWarnAt')
        ->assertDispatched('toast', fn (string $name, array $params): bool => ($params['message'] ?? '') === 'Budgeten varnar nu från 90 %.')
        ->set('form.value', '')
        ->call('save')
        ->assertHasErrors('form.value');

    $budget = CurrentCompany::run($this->company, fn () => Budget::query()->whereDate('week_start', '2026-11-23')->firstOrFail());
    Livewire::withQueryParams(['vecka' => '2026-11-23'])->test(BudgetPage::class)
        ->call('delete', $budget->id)
        ->assertDispatched('toast', fn (string $name, array $params): bool => ($params['message'] ?? '') === 'Budgeten för veckan är borttagen.');
});

it('answers 404 for another company\'s budgets and areas and never reads them', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    collect([PayrollModule::KEY, PlanningModule::KEY])->each(fn (string $key) => app(ModuleManager::class)->enable($key, $other, $otherOwner));
    $foreignArea = app(SaveArea::class)($otherOwner, $other, ['name' => 'Hemligt', 'colour' => '#e4572e']);
    app(SaveBudget::class)($otherOwner, $other, ['from' => '2026-11-23', 'kind' => 'amount', 'value' => '99 000']);
    $foreign = CurrentCompany::run($other, fn () => Budget::query()->firstOrFail());

    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    Livewire::test(BudgetPage::class)->call('delete', $foreign->id)->assertNotFound();
    Livewire::withQueryParams(['avdelning' => $foreignArea->id])->test(BudgetPage::class)->assertNotFound();
    expect(fn () => app(SaveBudget::class)($this->owner, $this->company, ['area_id' => $foreignArea->id, 'from' => '2026-11-23', 'kind' => 'amount', 'value' => '1']))->toThrow(ValidationException::class)
        ->and(p6Week($this)->isEmpty())->toBeTrue()
        // Copying A's empty week copies nothing of B's.
        ->and(app(SaveBudget::class)->copyForward($this->owner, $this->company, '2026-11-23', 2))->toBe(['copied' => 0, 'kept' => 0]);
});

it('shows each company\'s budget in the chain view, read only', function () {
    $viewer = User::factory()->create();
    $this->company->users()->attach($viewer, ['role' => 'scheduler']);
    [$otherOwner, $other] = Bookkeeping::company();
    $other->users()->attach($viewer, ['role' => 'scheduler']);
    collect([PayrollModule::KEY, PlanningModule::KEY])->each(fn (string $key) => app(ModuleManager::class)->enable($key, $other, $otherOwner));
    CurrentCompany::set($this->company);

    $shift = p6Shift($this);
    p6Budget($this, ['kind' => 'amount', 'value' => p6Kronor(intdiv((int) $shift->cost_estimate_ore, 2))]);

    $data = app(ChainSchedule::class)->for($viewer->fresh(), $this->monday);
    $blocks = collect($data['companies'])->keyBy('id');

    expect($blocks[$this->company->id]['extra']['budget'])->toMatchArray(['cost' => $shift->cost_estimate_ore, 'status' => BudgetLine::OVER])
        ->and($blocks[$this->company->id]['extra']['budget']['warning'])->toContain('är över budgeten')
        ->and($blocks[$other->id]['extra']['budget'])->toBeNull();
});

it('reads one company\'s budgets and the budget page in the same number of queries for a short and a long period', function () {
    $count = function (callable $read): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $read();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $data = fn (int $weeks) => app(BudgetData::class)->forCompany($this->company, array_map(fn (int $offset) => $this->monday->addWeeks($offset), range(0, $weeks - 1)));
    $page = fn () => Livewire::withQueryParams(['vecka' => '2026-11-23'])->test(BudgetPage::class);

    $kitchen = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Kök', 'colour' => '#e4572e']);
    p6Budget($this, ['kind' => 'percent', 'value' => '30']);
    p6Budget($this, ['area_id' => $kitchen->id, 'kind' => 'percent', 'value' => '10']);
    p6Shift($this);
    $data(1);
    $few = $count(fn () => $data(1));
    $page();
    $fewPage = $count($page);

    foreach (range(1, 8) as $week) {
        Bookkeeping::post($this->owner, $this->company, 100000 * $week, 1930, 3001, $this->monday->subWeeks($week)->addDay()->toDateString());
    }

    foreach (range(0, 13) as $offset) {
        p6Shift($this, $this->monday->addDays($offset)->toDateString(), '16:00', '20:00', $kitchen->id);
    }

    foreach (range(2, 6) as $number) {
        $area = app(SaveArea::class)($this->owner, $this->company, ['name' => "Avdelning {$number}", 'colour' => '#e4572e']);
        app(SaveBudget::class)($this->owner, $this->company, ['area_id' => $area->id, 'from' => '2026-11-23', 'to' => '2027-01-11', 'kind' => 'percent', 'value' => '20']);
    }

    expect($count(fn () => $data(8)))->toBe($few)
        ->and($count($page))->toBe($fewPage);
});

it('turns a save that loses a race for the same week into a Swedish validation message, not an error', function () {
    // The other save commits the week between this save's check and its insert.
    // A one-shot listener; flushing the model's listeners would also drop BelongsToCompany's.
    $companyId = $this->company->id;
    $fired = false;
    Budget::creating(function () use ($companyId, &$fired): void {
        if ($fired) {
            return;
        }

        $fired = true;
        DB::table('planning_budgets')->insert(['company_id' => $companyId, 'area_id' => null, 'week_start' => '2026-11-23', 'amount_ore' => 100, 'created_at' => now(), 'updated_at' => now()]);
    });

    try {
        p6Budget($this, ['kind' => 'amount', 'value' => '1 000']);
        test()->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['from'][0])->toBe('Någon annan sparade en budget för samma vecka samtidigt. Ladda om sidan och försök igen.');
    }

    expect(CurrentCompany::run($this->company, fn () => Budget::query()->count()))->toBe(0);
});

it('leaves out budgets on archived areas and takes no new ones there', function () {
    $kitchen = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Kök', 'colour' => '#e4572e']);
    p6Shift($this, area: $kitchen->id);
    p6Budget($this, ['area_id' => $kitchen->id, 'kind' => 'amount', 'value' => '100']);
    expect(p6Week($this)->areas)->toHaveKey($kitchen->id);

    app(SaveArea::class)->archive($this->owner, $this->company, $kitchen);

    expect(p6Week($this)->isEmpty())->toBeTrue()
        ->and(fn () => p6Budget($this, ['area_id' => $kitchen->id, 'kind' => 'amount', 'value' => '100']))->toThrow(ValidationException::class);
});

it('shows the budget of the day\'s own week in the day view', function () {
    app(SaveBudget::class)($this->owner, $this->company, ['from' => '2026-12-07', 'kind' => 'amount', 'value' => '10 000']);
    p6Shift($this, '2026-12-08');

    Livewire::withQueryParams(['vy' => 'dag', 'dag' => '2026-12-08'])->test(SchedulePage::class)
        ->assertSee('Budget vecka 50');
});
