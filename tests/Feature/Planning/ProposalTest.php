<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Planning\Actions\AcceptProposal;
use App\Modules\Planning\Actions\MakeProposal;
use App\Modules\Planning\Actions\PublishPeriod;
use App\Modules\Planning\Actions\SaveShift;
use App\Modules\Planning\Livewire\ProposalPage;
use App\Modules\Planning\Models\Availability;
use App\Modules\Planning\Models\Proposal;
use App\Modules\Planning\Models\ProposalItem;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;

/*
 * Schedule proposals, v1 (ADR-037 amendment, gap 3): /planering/forslag
 * proposes who should work the open shifts that exist, with reasons; a
 * planner accepts all or some and they become drafts, never published.
 * Absence and "Kan inte" are never proposed, a changed schedule makes the
 * proposal stale, the audit log holds ids and counts only, and the page
 * runs a fixed number of queries.
 */

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Europe/Stockholm'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Planning::setUp($this->company, $this->owner);
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->anna = Planning::employee($this->owner, $this->company, 'Anna', '900615123');
    $this->bertil = Planning::employee($this->owner, $this->company, 'Bertil', '850301234');
    $this->monday = CarbonImmutable::parse('2026-11-23', 'Europe/Stockholm');
});

function proposalOpenShift(string $date, string $start = '09:00', string $end = '17:00', array $extra = []): Shift
{
    return Planning::shift(test()->owner, test()->company, ['employee_id' => null, 'date' => $date, 'start' => $start, 'end' => $end, 'break_minutes' => 30, ...$extra]);
}

function proposalMake(string $from = '2026-11-23', string $to = '2026-11-29'): Proposal
{
    $test = test();

    return CurrentCompany::run($test->company, fn (): Proposal => app(MakeProposal::class)($test->owner, $test->company, ['from' => $from, 'to' => $to]));
}

it('is a planner\'s page, in the menu for planners only', function () {
    $this->get(route('planning.proposals'))->assertOk()->assertSee('Schemaförslag')->assertSee('Vad väger mest');
    $this->get(route('planning.schedule'))->assertSee(route('planning.proposals'), escape: false);

    $viewer = Bookkeeping::member($this->company, 'auditor');
    $employee = Bookkeeping::member($this->company, 'employee');
    $this->actingAs($employee)->get(route('planning.proposals'))->assertRedirect();
    $this->actingAs($viewer)->get(route('planning.proposals'))->assertForbidden();
});

it('proposes people for the open shifts with reasons, and changes nothing in the schedule', function () {
    $draft = proposalOpenShift('2026-11-24');
    $published = proposalOpenShift('2026-11-25', '17:00', '23:00');
    CurrentCompany::run($this->company, fn () => app(PublishPeriod::class)($this->owner, $this->company, $this->monday, $this->monday->addDays(6)));

    Livewire::test(ProposalPage::class)
        ->set('from', '2026-11-23')
        ->set('to', '2026-11-29')
        ->call('make')
        ->assertDispatched('toast', message: 'Förslaget är klart: 2 av 2 öppna pass har ett förslag. Inget i schemat är ändrat förrän du godkänner.')
        ->assertSeeHtml('data-item=')
        ->assertSee('Anna')
        ->assertSee('Under sin avtalade tid i perioden');

    $proposal = Proposal::query()->sole();

    expect($proposal->slot_count)->toBe(2)
        ->and($proposal->filled_count)->toBe(2)
        ->and($draft->fresh()->employee_id)->toBeNull()
        ->and($published->fresh()->employee_id)->toBeNull()
        ->and($published->fresh()->status)->toBe(Shift::PUBLISHED);

    $payload = AuditLog::query()->where('action', 'planning.proposal_made')->sole()->payload;
    expect($payload)->toEqual(['period_from' => '2026-11-23', 'period_to' => '2026-11-29', 'slots' => 2, 'filled' => 2, 'swaps_kept' => $proposal->swaps_kept, 'engine_version' => 'P7-1'])
        ->and(json_encode($payload))->not->toContain('Anna');
});

it('never proposes someone with approved absence or who cannot, and says so', function () {
    $shift = proposalOpenShift('2026-11-24');
    CurrentCompany::run($this->company, fn () => Absence::create(['employee_id' => $this->anna->id, 'type' => 'SICK', 'date_from' => '2026-11-24', 'date_to' => '2026-11-24', 'extent_bp' => 5000, 'hours' => '4', 'status' => 'APPROVED']));
    CurrentCompany::run($this->company, fn () => Availability::create(['employee_id' => $this->bertil->id, 'weekday' => 2, 'kind' => Availability::UNAVAILABLE]));

    $item = ProposalItem::query()->where('proposal_id', proposalMake()->id)->sole();

    expect($item->shift_id)->toBe($shift->id)
        ->and($item->employee_id)->toBeNull()
        ->and($item->reasons)->toEqual([['code' => 'blocked', 'unavailable' => 1, 'absent' => 1]]);

    Livewire::test(ProposalPage::class)
        ->assertSee('Inget förslag')
        ->assertSee('Ingen ledig kan ta passet: 1 kan inte den tiden, 1 är frånvarande.')
        ->assertDontSee('Sjuk');
});

it('checks "Kan inte" on every day a night shift touches', function () {
    // Friday 22:00 to Saturday 06:00; both have said that Saturday does not suit them.
    $night = proposalOpenShift('2026-11-27', '22:00', '06:00');

    foreach ([$this->anna, $this->bertil] as $employee) {
        CurrentCompany::run($this->company, fn () => Availability::create(['employee_id' => $employee->id, 'date' => '2026-11-28', 'kind' => Availability::UNAVAILABLE]));
    }

    $item = ProposalItem::query()->where('proposal_id', proposalMake()->id)->sole();

    expect($item->shift_id)->toBe($night->id)
        ->and($item->employee_id)->toBeNull()
        ->and($item->reasons)->toEqual([['code' => 'blocked', 'unavailable' => 2]]);

    // Saturday afternoon only does not touch the night shift.
    Availability::query()->update(['from' => '12:00', 'to' => '18:00']);
    $this->travel(1)->minutes();

    expect(ProposalItem::query()->where('proposal_id', proposalMake()->id)->sole()->employee_id)->not->toBeNull();
});

it('accepts the ticked shifts as drafts with the person, publishes nothing and audits counts', function () {
    $first = proposalOpenShift('2026-11-24');
    $second = proposalOpenShift('2026-11-26');
    CurrentCompany::run($this->company, fn () => app(PublishPeriod::class)($this->owner, $this->company, $this->monday, $this->monday->addDays(6)));
    $proposal = proposalMake();
    $items = ProposalItem::query()->where('proposal_id', $proposal->id)->orderBy('position')->get();

    Livewire::test(ProposalPage::class)
        ->assertSet('selected', $items->pluck('id')->all())
        ->set('selected', [(string) $items[0]->id])
        ->call('acceptSelected')
        ->assertDispatched('toast', message: '1 pass har fått en person som utkast. Publicera schemat när du är nöjd.')
        ->assertSeeHtml('data-accepted');

    $accepted = $first->fresh();
    expect($accepted->employee_id)->toBe($items[0]->employee_id)
        ->and($accepted->status)->toBe(Shift::DRAFT)
        ->and($accepted->source)->toBe('PROPOSAL')
        ->and($accepted->published_at)->not->toBeNull()
        ->and($second->fresh()->employee_id)->toBeNull()
        ->and($proposal->fresh()->accepted_count)->toBe(1)
        ->and($items[0]->fresh()->accepted_at)->not->toBeNull();

    Notification::assertNothingSent();
    expect(AuditLog::query()->where('action', 'planning.proposal_accepted')->sole()->payload)->toEqual(['accepted' => 1, 'filled' => 2, 'items' => [$items[0]->id]]);

    // A proposal is accepted once.
    expect(fn () => CurrentCompany::run($this->company, fn () => app(AcceptProposal::class)($this->owner, $this->company, $proposal->fresh())))
        ->toThrow(ValidationException::class, 'Förslaget är redan godkänt.');
});

it('accepts all with one click', function () {
    proposalOpenShift('2026-11-24');
    proposalOpenShift('2026-11-26');
    proposalMake();

    Livewire::test(ProposalPage::class)->call('acceptAll')
        ->assertDispatched('toast', message: '2 pass har fått en person som utkast. Publicera schemat när du är nöjd.');

    expect(Shift::query()->whereNull('employee_id')->count())->toBe(0)
        ->and(Shift::query()->where('source', 'PROPOSAL')->count())->toBe(2);
});

it('refuses a stale proposal: the schedule changed, or absence came in after it was made', function () {
    $open = proposalOpenShift('2026-11-24');
    $other = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-27', 'start' => '09:00', 'end' => '17:00', 'break_minutes' => 30]);
    proposalMake();

    $this->travel(5)->minutes();
    CurrentCompany::run($this->company, fn () => app(SaveShift::class)($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-27', 'start' => '10:00', 'end' => '17:00', 'break_minutes' => 30], $other));

    Livewire::test(ProposalPage::class)
        ->assertSeeHtml('data-stale')
        ->assertDontSee('Godkänn alla')
        ->call('acceptAll')
        ->assertDispatched('toast', message: 'Schemat har ändrats sedan förslaget gjordes. Gör ett nytt förslag.', type: 'error');

    expect($open->fresh()->employee_id)->toBeNull();

    // A fresh proposal, then approved absence for whoever got the shift: the re-check refuses it.
    $proposal = proposalMake();
    $item = ProposalItem::query()->where('proposal_id', $proposal->id)->whereNotNull('employee_id')->sole();
    CurrentCompany::run($this->company, fn () => Absence::create(['employee_id' => $item->employee_id, 'type' => 'VACATION', 'date_from' => '2026-11-24', 'date_to' => '2026-11-24', 'extent_bp' => 10000, 'hours' => '8', 'status' => 'APPROVED']));

    expect(fn () => CurrentCompany::run($this->company, fn () => app(AcceptProposal::class)($this->owner, $this->company, $proposal)))
        ->toThrow(ValidationException::class, 'Schemat har ändrats sedan förslaget gjordes.');
    expect($open->fresh()->employee_id)->toBeNull();
});

it('takes at most four weeks from today, and saves what weighs most', function () {
    expect(fn () => proposalMake('2026-11-17', '2026-11-20'))->toThrow(ValidationException::class, 'från och med idag')
        ->and(fn () => proposalMake('2026-11-23', '2026-12-21'))->toThrow(ValidationException::class, 'högst fyra veckor');

    Livewire::test(ProposalPage::class)
        ->set('weights.preference', 'HIGH')
        ->set('fairnessWeeks', 4)
        ->call('make');

    $settings = app(ModuleManager::class)->settings(PlanningModule::KEY, $this->company);
    expect($settings['proposal_weights']['preference'])->toBe('HIGH')
        ->and($settings['fairness_weeks'])->toBe(4)
        ->and(Proposal::query()->sole()->parameters['weights']['preference'])->toBe('HIGH');

    Livewire::test(ProposalPage::class)->assertSet('weights.preference', 'HIGH')->assertSet('fairnessWeeks', 4);
});

it('deletes proposals after 90 days', function () {
    proposalOpenShift('2026-11-24');
    $old = proposalMake();
    $this->travel(91)->days();
    $this->artisan('planning:prune-proposals')->assertSuccessful();

    expect(Proposal::query()->find($old->id))->toBeNull()
        ->and(ProposalItem::query()->where('proposal_id', $old->id)->count())->toBe(0);
});

it('shows a proposal in the same number of queries for 2 shifts and for 20', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ProposalPage::class)->call('$refresh');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    proposalOpenShift('2026-11-24');
    proposalOpenShift('2026-11-25');
    proposalMake();
    $count();
    $few = $count();

    $people = [];

    foreach (['880202345', '770303456', '660404567', '950505678'] as $index => $nine) {
        $people[] = Planning::employee($this->owner, $this->company, "Person{$index}", $nine);
    }

    foreach (range(0, 6) as $day) {
        $date = $this->monday->addDays($day)->toDateString();
        proposalOpenShift($date, '07:00', '12:00');
        proposalOpenShift($date, '17:00', '22:00');
        Planning::shift($this->owner, $this->company, ['employee_id' => $people[$day % 4]->id, 'date' => $date, 'start' => '12:00', 'end' => '17:00', 'break_minutes' => 30]);
    }

    $this->travel(1)->minutes();
    proposalMake();

    expect(Proposal::query()->latest('id')->first()->slot_count)->toBe(16)
        ->and($count())->toBe($few);
});

it('makes a proposal in the same number of queries for few and many people and shifts', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        proposalMake();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    proposalOpenShift('2026-11-24');
    $count();
    $few = $count();

    foreach (['880202345', '770303456', '660404567'] as $index => $nine) {
        $person = Planning::employee($this->owner, $this->company, "Person{$index}", $nine);
        Planning::shift($this->owner, $this->company, ['employee_id' => $person->id, 'date' => '2026-11-16', 'start' => '17:00', 'end' => '22:00', 'break_minutes' => 30]);
    }

    foreach (range(0, 6) as $day) {
        proposalOpenShift($this->monday->addDays($day)->toDateString(), '17:00', '22:00');
    }

    // Inserting the items is chunked by 500, so one insert either way.
    expect($count())->toBe($few);
});
