<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Planning\Actions\AcceptProposal;
use App\Modules\Planning\Actions\MakeProposal;
use App\Modules\Planning\Actions\SaveArea;
use App\Modules\Planning\Actions\SaveBudget;
use App\Modules\Planning\Actions\SaveStaffingRule;
use App\Modules\Planning\Domain\Proposal\ProposalEngine;
use App\Modules\Planning\Livewire\ProposalPage;
use App\Modules\Planning\Models\Proposal;
use App\Modules\Planning\Models\ProposalItem;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;

/*
 * Schedule proposals, v2 (ADR-037 amendment, gap 3, P8): with "Föreslå nya
 * pass utifrån bemanningsbehovet" on, the proposal also makes new shifts of
 * the company's lengths where the plan has fewer people than the staffing
 * target (gap 1), keeps them within the staff-cost budget (gap 2) costed
 * the same whoever takes them, proposes people for them under the same hard
 * rules as v1, and a planner accepts them as drafts. Per day only, the need
 * is named, never turned into shifts. Fixed query counts; P7's proposals
 * read as before.
 */

beforeEach(function () {
    Notification::fake();
    // Wednesday 18 November 2026; the period is week 48, 23–29 November.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Europe/Stockholm'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Planning::setUp($this->company, $this->owner);
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->anna = Planning::employee($this->owner, $this->company, 'Anna', '900615123', ['salary_form' => 'HOURLY', 'salary' => '150']);
    $this->bertil = Planning::employee($this->owner, $this->company, 'Bertil', '850301234', ['salary_form' => 'HOURLY', 'salary' => '150']);
});

/** Tuesdays 11–14: at least two people (a floor has hours even without sales). */
function p8Floor(object $test, int $staff = 2, ?int $areaId = null, string $start = '11:00', string $end = '14:00'): void
{
    app(SaveStaffingRule::class)($test->owner, $test->company, ['area_id' => $areaId, 'weekday' => 2, 'start' => $start, 'end' => $end, 'min_staff' => $staff, 'valid_from' => '2026-11-01']);
}

/** @param  array<string, mixed>  $extra */
function p8Make(object $test, array $extra = []): Proposal
{
    return CurrentCompany::run($test->company, fn (): Proposal => app(MakeProposal::class)($test->owner, $test->company, ['from' => '2026-11-23', 'to' => '2026-11-29', 'new_shifts' => true, ...$extra]));
}

/** @return Collection<int, ProposalItem> */
function p8Items(Proposal $proposal)
{
    return ProposalItem::query()->where('proposal_id', $proposal->id)->orderBy('position')->get();
}

it('turns the gap into new shifts of the allowed lengths, proposes people with reasons and changes nothing', function () {
    p8Floor($this);

    Livewire::test(ProposalPage::class)
        ->assertSet('newShifts', false)
        ->set('newShifts', true)
        ->set('from', '2026-11-23')
        ->set('to', '2026-11-29')
        ->call('make')
        ->assertDispatched('toast', message: 'Förslaget är klart: 2 av 2 pass har ett förslag, varav 2 nya pass utifrån bemanningsbehovet. Inget i schemat är ändrat förrän du godkänner.')
        ->assertSee('Nytt pass')
        ->assertSee('Nytt pass: täcker 3 timmar med för få i schemat')
        ->assertSeeHtml('data-new');

    $proposal = Proposal::query()->sole();
    $items = p8Items($proposal);

    // Two people 11–14: the shortest length (4 h) covers all three hours, one per person; no break under the five-hour rule.
    expect($proposal->engine_version)->toBe(ProposalEngine::VERSION)
        ->and($proposal->parameters['new_count'])->toBe(2)
        ->and($items)->toHaveCount(2)
        ->and($items->every(fn (ProposalItem $item): bool => $item->shift_id === null && $item->isNew()))->toBeTrue()
        ->and($items->map(fn (ProposalItem $item): string => $item->starts_at->format('Y-m-d H:i').'–'.$item->ends_at->format('H:i'))->all())->toBe(['2026-11-24 11:00–15:00', '2026-11-24 11:00–15:00'])
        ->and($items->pluck('break_minutes')->all())->toBe([0, 0])
        ->and($items->pluck('employee_id')->sort()->values()->all())->toBe([$this->anna->id, $this->bertil->id])
        ->and($items[0]->reasons[0])->toBe(['code' => 'covers_demand', 'hours' => 3])
        ->and(Shift::query()->count())->toBe(0);

    $payload = AuditLog::query()->where('action', 'planning.proposal_made')->sole()->payload;
    expect($payload)->toMatchArray(['slots' => 2, 'filled' => 2, 'new_shifts' => 2, 'over_budget' => 0, 'engine_version' => 'P8-1'])
        ->and(json_encode($payload))->not->toContain('Anna');

    // The setting is kept for the next proposal.
    expect(app(ModuleManager::class)->settings(PlanningModule::KEY, $this->company)['proposal_new_shifts'])->toBeTrue();
});

it('accepts new shifts as drafts with the person, links the items and publishes nothing', function () {
    p8Floor($this);
    $proposal = p8Make($this);

    Livewire::test(ProposalPage::class)->call('acceptAll')
        ->assertDispatched('toast', message: '2 pass har fått en person som utkast. Publicera schemat när du är nöjd.');

    $shifts = Shift::query()->orderBy('employee_id')->get();
    expect($shifts)->toHaveCount(2)
        ->and($shifts->pluck('source')->unique()->all())->toBe(['PROPOSAL'])
        ->and($shifts->pluck('status')->unique()->all())->toBe([Shift::DRAFT])
        ->and($shifts->pluck('employee_id')->all())->toBe([$this->anna->id, $this->bertil->id])
        ->and($shifts[0]->starts_at->format('Y-m-d H:i'))->toBe('2026-11-24 11:00')
        ->and(p8Items($proposal)->pluck('shift_id')->filter()->sort()->values()->all())->toBe($shifts->pluck('id')->sort()->values()->all());

    Notification::assertNothingSent();

    // Accepting created shifts in the period: another proposal from before is stale.
    $this->travel(1)->minutes();
    Shift::query()->delete();
    $other = p8Make($this);
    $this->travel(1)->minutes();
    CurrentCompany::run($this->company, fn () => app(AcceptProposal::class)($this->owner, $this->company, $other));
    expect(fn () => CurrentCompany::run($this->company, fn () => app(AcceptProposal::class)($this->owner, $this->company, $other->fresh())))
        ->toThrow(ValidationException::class, 'Förslaget är redan godkänt.');
});

it('never proposes a new shift against absence or the rest rules, and re-checks them on accepting', function () {
    p8Floor($this, 1);
    CurrentCompany::run($this->company, fn () => Absence::create(['employee_id' => $this->bertil->id, 'type' => 'SICK', 'date_from' => '2026-11-24', 'date_to' => '2026-11-24', 'extent_bp' => 10000, 'hours' => '8', 'status' => 'APPROVED']));
    // Anna ends at 02:00 on Tuesday: nine hours of rest before 11:00 is less than eleven.
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-23', 'start' => '18:00', 'end' => '02:00', 'break_minutes' => 30]);

    $item = p8Items(p8Make($this))->sole();

    expect($item->isNew())->toBeTrue()
        ->and($item->employee_id)->toBeNull()
        ->and($item->reasons)->toEqual([['code' => 'covers_demand', 'hours' => 3], ['code' => 'blocked', 'absent' => 1, 'rules' => 1]]);

    // Without her late shift Anna gets it; approved absence after the proposal makes it refuse.
    Shift::query()->delete();
    $this->travel(1)->minutes();
    $proposal = p8Make($this);
    expect(p8Items($proposal)->sole()->employee_id)->toBe($this->anna->id);

    CurrentCompany::run($this->company, fn () => Absence::create(['employee_id' => $this->anna->id, 'type' => 'VACATION', 'date_from' => '2026-11-24', 'date_to' => '2026-11-24', 'extent_bp' => 10000, 'hours' => '8', 'status' => 'APPROVED']));

    expect(fn () => CurrentCompany::run($this->company, fn () => app(AcceptProposal::class)($this->owner, $this->company, $proposal)))
        ->toThrow(ValidationException::class, 'Schemat har ändrats sedan förslaget gjordes.');
    expect(Shift::query()->count())->toBe(0);
});

it('keeps new shifts within the week\'s budget, costed the same whoever takes them', function () {
    // Anna on Monday sets the average cost per worked hour.
    $monday = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-23', 'start' => '09:00', 'end' => '14:00']);
    $cost = (int) $monday->cost_estimate_ore;
    $rate = intdiv($cost * 60 + 150, 300);
    expect($cost)->toBeGreaterThan(0);
    p8Floor($this);

    // Room for one more four-hour shift, not two.
    $budget = intdiv($cost + $rate * 4, 100) + 1;
    app(SaveBudget::class)($this->owner, $this->company, ['from' => '2026-11-23', 'kind' => SaveBudget::AMOUNT, 'value' => (string) $budget]);

    $proposal = p8Make($this);
    $items = p8Items($proposal);

    expect($items)->toHaveCount(2)
        ->and($items[0]->employee_id)->not->toBeNull()
        ->and($items[1]->employee_id)->toBeNull()
        ->and($items[1]->reasons)->toEqual([['code' => 'covers_demand', 'hours' => 3], ['code' => 'over_budget']])
        ->and($proposal->parameters['over_budget'])->toBe(1)
        ->and($proposal->parameters['estimates'])->toBe(['2026-11-23' => $rate * 4])
        ->and($proposal->filled_count)->toBe(1);

    Livewire::test(ProposalPage::class)
        ->assertSee('Ryms inte i veckans budget för personalkostnad.')
        ->assertSee('Nya pass vecka 48');

    // Without a budget there is no limit.
    DB::table('planning_budgets')->delete();
    $this->travel(1)->minutes();
    expect(p8Items(p8Make($this))->whereNotNull('employee_id'))->toHaveCount(2);
});

it('names a need that exists per day only instead of placing it in time', function () {
    // 12 000 kr forecast for a Tuesday at 1 000 kr per staffed hour = 12 staff-hours, with no till and no hour profile.
    Bookkeeping::post($this->owner, $this->company, 900000, 1930, 3001, '2026-11-10');
    Bookkeeping::post($this->owner, $this->company, 1500000, 1930, 3001, '2026-11-03');
    app(SaveStaffingRule::class)($this->owner, $this->company, ['weekday' => 2, 'start' => '10:00', 'end' => '18:00', 'sales_per_staff_hour' => '1 000', 'valid_from' => '2026-11-01']);

    $proposal = p8Make($this);

    expect($proposal->slot_count)->toBe(0)
        ->and($proposal->parameters['unplaced'])->toBe(['2026-11-24' => 720]);

    Livewire::test(ProposalPage::class)
        ->assertSeeHtml('data-unplaced')
        ->assertSee('Behovet finns bara per dag för');
});

it('plans per area when areas have targets of their own, and never on archived areas', function () {
    $kitchen = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Kök', 'colour' => '#e4572e']);
    $archived = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Gammal', 'colour' => '#2e86e4']);
    p8Floor($this, 1, $kitchen->id);
    p8Floor($this, 1, $archived->id);
    // The whole company's target is not used when areas have their own: it would count the need twice.
    p8Floor($this, 2);
    $archived->forceFill(['archived_at' => now()])->save();

    $items = p8Items(p8Make($this));

    expect($items)->toHaveCount(1)
        ->and($items[0]->area_id)->toBe($kitchen->id);

    Livewire::test(ProposalPage::class)->assertSee('Kök')->assertSeeHtml('data-company-ignored');
});

it('refuses a new shift whose start has passed, and one the budget no longer holds', function () {
    p8Floor($this, 1);
    $proposal = p8Make($this);
    expect(p8Items($proposal)->sole()->employee_id)->not->toBeNull();

    // Tuesday 11:30: the new shift 11–15 has started.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 11:30:00', 'Europe/Stockholm'));

    Livewire::test(ProposalPage::class)
        ->assertSeeHtml('data-stale')
        ->call('acceptAll')
        ->assertDispatched('toast', message: AcceptProposal::staleMessage(), type: 'error');
    expect(Shift::query()->count())->toBe(0);

    // A budget lowered after the proposal was made: the re-check refuses it.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Europe/Stockholm'));
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-16', 'start' => '09:00', 'end' => '14:00']);
    $this->travel(1)->minutes();
    $fresh = p8Make($this);
    expect(p8Items($fresh)->sole()->employee_id)->not->toBeNull();
    app(SaveBudget::class)($this->owner, $this->company, ['from' => '2026-11-23', 'kind' => SaveBudget::AMOUNT, 'value' => '1']);

    expect(fn () => CurrentCompany::run($this->company, fn () => app(AcceptProposal::class)($this->owner, $this->company, $fresh)))
        ->toThrow(ValidationException::class, 'Schemat har ändrats sedan förslaget gjordes.');
    expect(Shift::query()->where('source', 'PROPOSAL')->count())->toBe(0);
});

it('gives a long new shift its break also when the break warning is switched off', function () {
    p8Floor($this, 1, null, '09:00', '17:00');
    $rules = app(ModuleManager::class)->settings(PlanningModule::KEY, $this->company)['working_time_rules'] ?? [];
    $rules['max_without_break'] = ['value' => 300, 'enabled' => false];
    app(ModuleManager::class)->updateSettings(PlanningModule::KEY, $this->company, $this->owner, ['working_time_rules' => $rules]);

    $item = p8Items(p8Make($this, ['shift_hours' => '8']))->sole();

    expect($item->break_minutes)->toBe(30);
    Livewire::test(ProposalPage::class)->assertSee('30 min rast');
});

it('takes the lengths and the break from the form, saves them and refuses lengths it cannot use', function () {
    p8Floor($this, 1, null, '09:00', '17:00');

    $items = p8Items(p8Make($this, ['shift_hours' => '8', 'break_minutes' => 45]));

    expect($items)->toHaveCount(1)
        ->and($items[0]->starts_at->format('H:i').'–'.$items[0]->ends_at->format('H:i'))->toBe('09:00–17:00')
        ->and($items[0]->break_minutes)->toBe(45);

    $settings = app(ModuleManager::class)->settings(PlanningModule::KEY, $this->company);
    expect($settings['proposal_shift_hours'])->toBe([8])
        ->and($settings['proposal_break_minutes'])->toBe(45);

    expect(fn () => p8Make($this, ['shift_hours' => '1, 13']))->toThrow(ValidationException::class, 'hela timmar mellan 2 och 10');

    Livewire::test(ProposalPage::class)->assertSet('shiftHours', '8')->assertSet('breakMinutes', 45)
        ->set('shiftHours', 'x')
        ->call('make')
        ->assertDispatched('toast', type: 'error');
});

it('still shows a proposal saved by the first version', function () {
    $open = Planning::shift($this->owner, $this->company, ['employee_id' => null, 'date' => '2026-11-24', 'start' => '09:00', 'end' => '17:00', 'break_minutes' => 30]);
    $proposal = CurrentCompany::run($this->company, fn () => Proposal::query()->create([
        'period_from' => '2026-11-23', 'period_to' => '2026-11-29', 'engine_version' => 'P7-1', 'slot_count' => 1, 'filled_count' => 1, 'swaps_kept' => 0, 'created_by' => $this->owner->id,
        'parameters' => ['weights' => [], 'fairness_weeks' => 8, 'unsocial_from' => '19:00', 'unsocial_to' => '07:00', 'swaps_tried' => 0],
    ]));
    CurrentCompany::run($this->company, fn () => ProposalItem::query()->create(['proposal_id' => $proposal->id, 'shift_id' => $open->id, 'employee_id' => $this->anna->id, 'position' => 0, 'reasons' => [['code' => 'balanced']]]));

    Livewire::withQueryParams(['forslag' => $proposal->id])->test(ProposalPage::class)
        ->assertSee('Anna')
        ->assertSee('09:00–17:00')
        ->assertDontSee('Nytt pass')
        ->call('acceptAll')
        ->assertDispatched('toast', message: '1 pass har fått en person som utkast. Publicera schemat när du är nöjd.');

    expect($open->fresh()->employee_id)->toBe($this->anna->id);
});

it('makes and shows a proposal with new shifts in the same number of queries for little and much', function () {
    $make = function (): int {
        test()->travel(1)->minutes();
        DB::flushQueryLog();
        DB::enableQueryLog();
        p8Make(test());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $show = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ProposalPage::class)->call('$refresh');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $kitchen = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Kök', 'colour' => '#e4572e']);
    p8Floor($this, 1, $kitchen->id);
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-16', 'start' => '09:00', 'end' => '14:00', 'area_id' => $kitchen->id]);
    app(SaveBudget::class)($this->owner, $this->company, ['from' => '2026-11-23', 'kind' => SaveBudget::AMOUNT, 'value' => '100000']);
    $make();
    $fewMake = $make();
    $show();
    $fewShow = $show();
    $few = Proposal::query()->latest('id')->first()->slot_count;

    // More areas with targets, more days, more people and more shifts.
    $bar = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Bar', 'colour' => '#2e86e4']);
    $hall = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Servering', 'colour' => '#3b8f4e']);

    foreach ([$bar, $hall] as $area) {
        foreach ([1, 3, 5, 6] as $weekday) {
            app(SaveStaffingRule::class)($this->owner, $this->company, ['area_id' => $area->id, 'weekday' => $weekday, 'start' => '10:00', 'end' => '20:00', 'min_staff' => 2, 'valid_from' => '2026-11-01']);
        }
    }

    foreach (['880202345', '770303456', '660404567', '950505678'] as $index => $nine) {
        $person = Planning::employee($this->owner, $this->company, "Person{$index}", $nine, ['salary_form' => 'HOURLY', 'salary' => '150']);
        Planning::shift($this->owner, $this->company, ['employee_id' => $person->id, 'date' => '2026-11-1'.(6 + $index), 'start' => '09:00', 'end' => '14:00', 'area_id' => $bar->id]);
    }

    app(SaveBudget::class)($this->owner, $this->company, ['area_id' => $bar->id, 'from' => '2026-11-23', 'kind' => SaveBudget::AMOUNT, 'value' => '2000']);

    expect($make())->toBe($fewMake)
        ->and(Proposal::query()->latest('id')->first()->slot_count)->toBeGreaterThan($few + 10)
        ->and($show())->toBe($fewShow);
});
