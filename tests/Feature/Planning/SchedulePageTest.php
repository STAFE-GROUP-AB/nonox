<?php

declare(strict_types=1);

use App\Modules\Agreements\Actions\AgreementSetup;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Planning\Actions\PublishPeriod;
use App\Modules\Planning\Actions\SaveArea;
use App\Modules\Planning\Livewire\SchedulePage;
use App\Modules\Planning\Livewire\SetupPage;
use App\Modules\Planning\Livewire\TemplatesPage;
use App\Modules\Planning\Models\Availability;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\Skill;
use App\Modules\Planning\Models\Template;
use App\Modules\Planning\Support\ScheduleData;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;

/*
 * Planering → Schema, Mallar and Inställningar (PLANNING_MODULE.md §7): the
 * grid's gestures each make one call, the footer shows hours, cost, sales
 * from the books and labour cost %, and the week loads in a fixed number of
 * queries however many shifts it has.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'Europe/Stockholm'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Planning::setUp($this->company, $this->owner);
    $this->actingAs($this->owner);
    $this->anna = Planning::employee($this->owner, $this->company, 'Anna', '900615123', ['salary_form' => 'HOURLY', 'salary' => '150']);
    $this->area = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Servering', 'colour' => '#2e86ab']);
});

it('shows the week with people, open shifts, holidays and warnings', function () {
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $this->area->id, 'date' => '2026-11-02', 'start' => '16:00', 'end' => '23:00', 'break_minutes' => 30]);
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-03', 'start' => '07:00', 'end' => '12:00']);
    Planning::shift($this->owner, $this->company, ['date' => '2026-11-07', 'start' => '10:00', 'end' => '15:00', 'break_minutes' => 30]);

    Livewire::withQueryParams(['vecka' => '2026-11-02'])->test(SchedulePage::class)
        ->assertSee('Vecka 45')
        ->assertSee('Anna Kock')
        ->assertSee('Öppna pass')
        ->assertSee('Servering')
        ->assertSee('Bara 8 h vila före passet. Dygnsvilan ska vara minst 11 h.')
        ->assertSee('Publicera (3)');

    Livewire::withQueryParams(['vecka' => '2026-10-26'])->test(SchedulePage::class)->assertSee('Alla helgons dag');
});

it('moves, creates, copies and removes shifts from the grid, one call each', function () {
    $shift = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00']);
    $page = Livewire::withQueryParams(['vecka' => '2026-11-09'])->test(SchedulePage::class);

    $page->call('moveShift', $shift->id, null, '2026-11-11 09:00', '2026-11-11 17:30')->assertReturned(true)->assertDispatched('toast', message: 'Passet är flyttat.');
    expect($shift->fresh()->employee_id)->toBeNull()
        ->and($shift->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-11-11 09:00');

    $page->call('moveShift', $shift->id, null, '2026-11-11 09:00', '2026-11-11 08:00')->assertReturned(false)->assertDispatched('toast', type: 'error');

    $page->call('createShift', $this->anna->id, '2026-11-12 10:00', '2026-11-12 14:00')->assertReturned(true);
    $page->call('copyShift', $shift->id, $this->anna->id, '2026-11-13')->assertReturned(true);
    expect(Shift::query()->count())->toBe(3);

    $page->call('moveShiftToArea', $shift->id, $this->area->id, '2026-11-11 09:00', '2026-11-11 17:30')->assertReturned(true);
    expect($shift->fresh()->area_id)->toBe($this->area->id);

    $page->call('deleteShift', $shift->id)->assertDispatched('toast', message: 'Utkastet är borttaget.');
    expect(Shift::query()->count())->toBe(2);
});

it('edits a shift in the editor and publishes the week with a summary', function () {
    $page = Livewire::withQueryParams(['vecka' => '2026-11-09'])->test(SchedulePage::class)
        ->call('create', $this->anna->id, '2026-11-10')
        ->assertSet('showEditor', true)
        ->set('form.start', '17:00')
        ->set('form.end', '01:00')
        ->set('form.break_minutes', 30)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showEditor', false);

    $shift = Shift::query()->sole();
    expect($shift->ends_at->format('Y-m-d H:i'))->toBe('2026-11-11 01:00');

    $page->call('edit', $shift->id)->set('form.end', '17:15')->call('save')->assertHasErrors('form.break_minutes');

    $page->call('openPublish')->assertSee('1 nytt pass.')->assertSee('Saknar inloggning och e-post')
        ->call('publish')
        ->assertDispatched('toast', message: '1 pass är publicerat. Ingen har fått besked.');

    expect($shift->fresh()->status)->toBe(Shift::PUBLISHED);
});

it('copies the week and lays out templates from the toolbar', function () {
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00']);

    Livewire::withQueryParams(['vecka' => '2026-11-09'])->test(SchedulePage::class)
        ->call('openCopy')->assertSet('copy.target', '2026-11-16')->call('copyWeek')
        ->assertDispatched('toast', message: '1 pass är kopierat som utkast.')
        ->call('openTemplate', 'save')->set('template.name', 'Grund')->call('applyTemplate')
        ->assertDispatched('toast', message: 'Veckan är sparad som mallen Grund.');

    Livewire::withQueryParams(['vecka' => '2026-11-23'])->test(SchedulePage::class)
        ->call('openTemplate', 'apply')->assertSet('template.template', Template::query()->value('id'))->call('applyTemplate')
        ->assertDispatched('toast', message: '1 pass från mallen är tillagt som utkast.');
});

it('sums hours, cost, sales from the books and labour cost per day', function () {
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-02', 'start' => '08:00', 'end' => '12:00']);
    Bookkeeping::post($this->owner, $this->company, 400000, 1930, 3001, '2026-11-02');
    Bookkeeping::post($this->owner, $this->company, 300000, 1930, 3001, '2026-10-27');
    Bookkeeping::post($this->owner, $this->company, 999900, 1930, 3001, '2026-11-02');

    $data = app(ScheduleData::class)->load($this->company, CarbonImmutable::parse('2026-11-02', 'Europe/Stockholm'), CarbonImmutable::parse('2026-11-08', 'Europe/Stockholm'));
    $monday = $data['totals']['2026-11-02'];
    $tuesday = $data['totals']['2026-11-03'];

    // 4 h × 150 kr + 12 % + 31,42 % = 600 + 72 + 211,14 = 883,14 kr (verified test rules).
    expect($monday['minutes'])->toBe(240)
        ->and($monday['cost'])->toBe(88314)
        ->and($monday['incomplete'])->toBeFalse()
        ->and($monday['sales'])->toBe(1_399_900)
        ->and($monday['percent'])->toBe(6.3)
        ->and($tuesday['sales'])->toBeNull()
        ->and($tuesday['reference'])->toBe(300000);
});

it('prices OB from a checked agreement version and names an unchecked one', function () {
    app(ModuleManager::class)->enable(AgreementsModule::KEY, $this->company, $this->owner);
    $setup = app(AgreementSetup::class);
    $binding = $setup->saveBinding($this->owner, $this->company, ['agreement_key' => 'visita-hrf-grona-riks', 'variant' => 'MEMBER', 'bound_from' => '2025-01-01']);
    $setup->saveEmployment($this->owner, $this->company, Employment::query()->where('employee_id', $this->anna->id)->sole(), ['collective_agreement_id' => $binding->id, 'agreement_group' => '2']);

    $unchecked = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-03', 'start' => '18:00', 'end' => '22:00']);
    expect($unchecked->cost_details['missing'])->toBe(['OB: avtalsversionen är inte kontrollerad.']);

    AgreementRuleVersion::query()->where('agreement_key', 'visita-hrf-grona-riks')->where('variant', 'MEMBER')->sole()->forceFill(['verified_by' => 'Granskare', 'verified_at' => now()])->save();
    $checked = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-04', 'start' => '18:00', 'end' => '22:00']);

    // 2 h OB at 27,59 kr = 55,18 kr on top of 600 kr.
    expect($checked->cost_details['ob_minutes'])->toBe(['GR_OB' => 120])
        ->and($checked->cost_details['ob_ore'])->toBe(5518)
        ->and($checked->cost_details['missing'])->toBe([]);
});

it('loads the week in the same number of queries for 2 shifts and for 40', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::withQueryParams(['vecka' => '2026-11-09'])->test(SchedulePage::class)->call('$refresh');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $people = [$this->anna];
    $people[] = Planning::employee($this->owner, $this->company, 'Bertil', '850301234');
    Planning::shift($this->owner, $this->company, ['employee_id' => $people[0]->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00']);
    Planning::shift($this->owner, $this->company, ['employee_id' => $people[1]->id, 'area_id' => $this->area->id, 'date' => '2026-11-11', 'start' => '08:00', 'end' => '16:00']);
    // The first request also loads the signed-in user's company once.
    $count();
    $few = $count();

    foreach (['880202345', '770303456', '660404567'] as $index => $nine) {
        $people[] = Planning::employee($this->owner, $this->company, "Person{$index}", $nine);
    }

    foreach ($people as $person) {
        foreach (range(9, 15) as $day) {
            Planning::shift($this->owner, $this->company, ['employee_id' => $person->id, 'area_id' => $this->area->id, 'date' => sprintf('2026-11-%02d', $day), 'start' => '10:00', 'end' => '14:00']);
        }
    }

    expect($count())->toBe($few);
});

it('reads as a list on a phone and as a timeline for a day', function () {
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00']);

    Livewire::withQueryParams(['vy' => 'dag', 'dag' => '2026-11-10'])->test(SchedulePage::class)
        ->assertSee('Tisdag 10 november 2026')
        ->assertSee('data-start-min="480"', false)
        ->call('next')->assertSet('day', '2026-11-11')
        ->call('showWeek')->assertSet('view', 'vecka');
});

it('manages areas, skills and templates on their pages', function () {
    Livewire::test(SetupPage::class)
        ->call('createArea')->set('form.name', 'Bar')->call('saveArea')->assertHasNoErrors()->assertSee('Bar')
        ->set('skillName', 'Kassa')->call('addSkill')->assertDispatched('toast', message: 'Kompetensen är tillagd.')
        ->call('toggleSkill', $this->anna->id, Skill::query()->value('id'))
        ->assertDispatched('toast', message: 'Kompetenserna för Anna är sparade.');

    Livewire::test(TemplatesPage::class)
        ->call('create')->set('form.name', 'Helg')->set('form.weeks', 2)->call('save')->assertHasNoErrors()
        ->set('shift.week_index', 1)->set('shift.weekday', 6)->set('shift.start', '10:00')->set('shift.end', '18:00')
        ->call('addShift')->assertHasNoErrors()->assertSee('10:00–18:00')
        ->set('apply.week', '2026-11-16')->call('applyTemplate')
        ->assertDispatched('toast', message: '1 pass från mallen är tillagt som utkast.');

    expect(Shift::query()->sole()->starts_at->format('Y-m-d H:i'))->toBe('2026-11-28 10:00');
});

it('is read-only for someone who can only view', function () {
    $viewer = Bookkeeping::member($this->company, 'accountant');
    $this->actingAs($viewer);

    Livewire::test(SchedulePage::class)->assertForbidden();
});

it('does not let a page reach another company\'s shift', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    app(ModuleManager::class)->enable('payroll', $other, $otherOwner);
    app(ModuleManager::class)->enable('planning', $other, $otherOwner);
    $theirs = Planning::shift($otherOwner, $other, ['date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00']);
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    Livewire::withQueryParams(['vecka' => '2026-11-09'])->test(SchedulePage::class)
        ->assertDontSee($theirs->id)
        ->call('moveShift', $theirs->id, null, '2026-11-11 09:00', '2026-11-11 17:00')
        ->assertNotFound();
});

it('warns when a shift falls on a time the employee said does not suit them, and counts it on publish', function () {
    Availability::create(['employee_id' => $this->anna->id, 'weekday' => 2, 'kind' => 'UNAVAILABLE']);
    Availability::create(['employee_id' => $this->anna->id, 'date' => '2026-11-12', 'from' => '08:00', 'to' => '12:00', 'kind' => 'UNAVAILABLE']);
    // A row for the date decides over the weekly row: Tuesday 17 November suits after all.
    Availability::create(['employee_id' => $this->anna->id, 'date' => '2026-11-17', 'kind' => 'AVAILABLE']);

    $tuesday = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '10:00', 'end' => '14:00']);
    $morning = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-12', 'start' => '11:00', 'end' => '15:00']);
    $afternoon = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-13', 'start' => '13:00', 'end' => '17:00']);
    $nextTuesday = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-17', 'start' => '10:00', 'end' => '14:00']);

    $week = app(ScheduleData::class)->load($this->company, CarbonImmutable::parse('2026-11-09', 'Europe/Stockholm'), CarbonImmutable::parse('2026-11-22', 'Europe/Stockholm'));
    $rules = fn (Shift $shift): array => array_map(fn ($warning): string => $warning->rule, $week['warnings'][$shift->id] ?? []);

    expect($rules($tuesday))->toBe(['unavailable'])
        ->and($rules($morning))->toBe(['unavailable'])
        ->and($rules($afternoon))->toBe([])
        ->and($rules($nextTuesday))->toBe([])
        ->and(PublishPeriod::summary(CarbonImmutable::parse('2026-11-09', 'Europe/Stockholm'), CarbonImmutable::parse('2026-11-15', 'Europe/Stockholm'))['unavailable'])->toBe(2);

    Livewire::withQueryParams(['vecka' => '2026-11-09'])->test(SchedulePage::class)
        ->assertSee('Har angett att den tiden inte passar.')
        ->call('openPublish')
        ->assertSee('2 pass ligger på tider som personerna har sagt inte passar.');
});

it('says when the person is told about a change to a published shift', function () {
    $shift = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00']);
    app(PublishPeriod::class)($this->owner, $this->company, CarbonImmutable::parse('2026-11-09', 'Europe/Stockholm'), CarbonImmutable::parse('2026-11-15', 'Europe/Stockholm'));

    $page = Livewire::withQueryParams(['vecka' => '2026-11-09'])->test(SchedulePage::class)
        ->call('edit', $shift->id)->set('form.start', '09:00')->call('save')
        ->assertDispatched('toast', message: 'Passet är sparat. Personen får besked när du publicerar igen.');

    $page->call('moveShift', $shift->id, $this->anna->id, '2026-11-11 09:00', '2026-11-11 16:00')
        ->assertDispatched('toast', message: 'Passet är flyttat. Personen får besked när du publicerar igen.');

    app(ModuleManager::class)->updateSettings('planning', $this->company, $this->owner, ['notify_on_publish' => false]);
    $page->call('moveShift', $shift->id, $this->anna->id, '2026-11-12 09:00', '2026-11-12 16:00')
        ->assertDispatched('toast', message: 'Passet är flyttat. Publicera igen för att visa ändringen. Aviseringar är avstängda, så personen får inget besked.');

    $draft = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-13', 'start' => '08:00', 'end' => '12:00']);
    $page->call('moveShift', $draft->id, $this->anna->id, '2026-11-14 08:00', '2026-11-14 12:00')
        ->assertDispatched('toast', message: 'Passet är flyttat.');
});
