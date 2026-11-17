<?php

declare(strict_types=1);

use App\Domain\Firm\ChainSchedule;
use App\Livewire\Firm\ChainSchedule as ChainScheduleComponent;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SavedView;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;

/*
 * Schema över företag (ADR-037 amendment, gap 8): a read-only week across the
 * companies where the user may see the schedule, conflicts only through the
 * same login and only among companies the user may see, and a fixed number of
 * queries whatever the number of companies.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'Europe/Stockholm'));
    $this->monday = CarbonImmutable::parse('2026-11-02', 'Europe/Stockholm');
    $this->viewer = User::factory()->create(['name' => 'Vera Planerare']);
});

/** A company with Planering on, where $viewer holds $role. */
function chainCompany(string $name, string $role = 'scheduler', bool $planning = true): Company
{
    [$owner, $company] = Bookkeeping::company();
    $company->forceFill(['name' => $name])->save();
    $company->users()->attach(test()->viewer, ['role' => $role]);

    if ($planning) {
        // The tax tables and rules are global; the first company imports them, the others only switch the modules on.
        if (DB::table('tax_table_imports')->exists()) {
            app(ModuleManager::class)->enable(PayrollModule::KEY, $company, $owner);
            app(ModuleManager::class)->enable(PlanningModule::KEY, $company, $owner);
            CurrentCompany::set($company);
        } else {
            Planning::setUp($company, $owner);
        }
    }

    return $company;
}

function chainEmployee(Company $company, string $first, ?User $login = null, string $nine = '900615123'): void
{
    Planning::employee($company->owner, $company, $first, $nine, $login === null ? [] : ['user_id' => $login->id]);
}

function chainShift(Company $company, string $first, string $date, string $start, string $end): Shift
{
    $employee = Employee::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('first_name', $first)->firstOrFail();

    return Planning::shift($company->owner, $company, ['employee_id' => $employee->id, 'date' => $date, 'start' => $start, 'end' => $end]);
}

it('shows the week of every company where the user may see the schedule, and only those', function () {
    $cafe = chainCompany('Café Hamnen AB');
    $bistro = chainCompany('Bistro Torget AB');
    $employeeOnly = chainCompany('Bara Anställd AB', 'employee');
    $off = chainCompany('Utan Planering AB', 'scheduler', planning: false);
    $stranger = chainCompany('Främmande AB', 'scheduler');
    $stranger->users()->detach($this->viewer);

    foreach ([$cafe, $bistro, $employeeOnly, $stranger] as $company) {
        chainEmployee($company, 'Anna');
    }

    chainShift($cafe, 'Anna', '2026-11-03', '08:00', '16:00');
    chainShift($bistro, 'Anna', '2026-11-04', '10:00', '18:00');
    chainShift($employeeOnly, 'Anna', '2026-11-04', '07:00', '15:00');
    chainShift($stranger, 'Anna', '2026-11-04', '07:00', '15:00');

    $data = app(ChainSchedule::class)->for($this->viewer->fresh(), $this->monday);

    expect(array_values($data['eligible']))->toEqualCanonicalizing(['Café Hamnen AB', 'Bistro Torget AB'])
        ->and(collect($data['companies'])->pluck('name')->all())->toEqualCanonicalizing(['Café Hamnen AB', 'Bistro Torget AB'])
        ->and($data['totals']['shifts'])->toBe(2)
        ->and($data['totals']['minutes'])->toBe(16 * 60);

    $this->actingAs($this->viewer->fresh());
    Livewire::test(ChainScheduleComponent::class)
        ->assertSee('Café Hamnen AB')
        ->assertSee('Bistro Torget AB')
        ->assertSee('08:00–16:00')
        ->assertDontSee('Bara Anställd AB')
        ->assertDontSee('Utan Planering AB')
        ->assertDontSee('Främmande AB');
});

it('cuts a requested selection down to what the user may see', function () {
    $cafe = chainCompany('Café Hamnen AB');
    $other = Bookkeeping::company()[1];
    chainEmployee($cafe, 'Anna');
    chainShift($cafe, 'Anna', '2026-11-03', '08:00', '16:00');

    $data = app(ChainSchedule::class)->for($this->viewer->fresh(), $this->monday, [$other->id]);

    expect($data['selected'])->toBe([$cafe->id])
        ->and(collect($data['companies'])->pluck('id')->all())->toBe([$cafe->id]);

    $both = chainCompany('Bistro Torget AB');
    $only = app(ChainSchedule::class)->for($this->viewer->fresh(), $this->monday, [$both->id, $other->id]);

    expect($only['selected'])->toBe([$both->id]);
});

it('saves the chosen companies as a named view and applies it again, re-checked against the memberships', function () {
    $cafe = chainCompany('Café Hamnen AB');
    $bistro = chainCompany('Bistro Torget AB');
    $this->actingAs($this->viewer->fresh());

    Livewire::test(ChainScheduleComponent::class)
        ->set('chosen', [(string) $bistro->id])
        ->set('viewName', 'Bara bistron')
        ->call('saveView')
        ->assertSee('Bara bistron')
        ->set('chosen', [])
        ->call('applyView', SavedView::query()->where('name', 'Bara bistron')->value('id'))
        ->assertSet('chosen', [(string) $bistro->id]);

    $view = SavedView::query()->where('user_id', $this->viewer->id)->where('page', 'planning-chain')->firstOrFail();
    expect($view->filters)->toBe(['foretag' => (string) $bistro->id]);

    // The user leaves the bistro: the saved view no longer widens anything.
    $bistro->users()->detach($this->viewer);
    $data = app(ChainSchedule::class)->for($this->viewer->fresh(), $this->monday, [$bistro->id]);

    expect($data['selected'])->toBe([$cafe->id]);
});

it('does not apply another user\'s saved view', function () {
    chainCompany('Café Hamnen AB');
    $someone = User::factory()->create();
    $theirs = SavedView::query()->create(['user_id' => $someone->id, 'page' => 'planning-chain', 'name' => 'Deras', 'filters' => ['foretag' => '1']]);
    $this->actingAs($this->viewer->fresh());

    Livewire::test(ChainScheduleComponent::class)->call('applyView', $theirs->id)->assertNotFound();
});

it('warns about overlap and too short a rest for the same login across companies', function () {
    $cafe = chainCompany('Café Hamnen AB');
    $bistro = chainCompany('Bistro Torget AB');
    $login = User::factory()->create();
    $cafe->users()->attach($login, ['role' => 'employee']);
    $bistro->users()->attach($login, ['role' => 'employee']);
    chainEmployee($cafe, 'Anna', $login);
    chainEmployee($bistro, 'Anna', $login);

    chainShift($cafe, 'Anna', '2026-11-03', '08:00', '16:00');
    chainShift($bistro, 'Anna', '2026-11-03', '15:00', '20:00');
    chainShift($bistro, 'Anna', '2026-11-04', '02:00', '07:00');

    $data = app(ChainSchedule::class)->for($this->viewer->fresh(), $this->monday);
    $kinds = collect($data['conflicts'])->pluck('kind')->all();

    expect($kinds)->toContain('overlap')->toContain('rest');

    $this->actingAs($this->viewer->fresh());
    Livewire::test(ChainScheduleComponent::class)
        ->assertSee('Krockar mellan företag')
        ->assertSee('Passet överlappar ett pass hos samma person i Café Hamnen AB.')
        ->assertSee('Dygnsvilan ska vara minst 11 h.');
});

it('does not link two people who are not the same login, or one person in one company', function () {
    $cafe = chainCompany('Café Hamnen AB');
    $bistro = chainCompany('Bistro Torget AB');
    chainEmployee($cafe, 'Anna', null);
    chainEmployee($bistro, 'Anna', null);
    chainShift($cafe, 'Anna', '2026-11-03', '08:00', '16:00');
    chainShift($bistro, 'Anna', '2026-11-03', '15:00', '20:00');

    expect(app(ChainSchedule::class)->for($this->viewer->fresh(), $this->monday)['conflicts'])->toBe([]);
});

it('reveals no conflict with a company the viewer may not see', function () {
    $cafe = chainCompany('Café Hamnen AB');
    $hidden = chainCompany('Dolda Bolaget AB', 'employee');
    $login = User::factory()->create();
    $cafe->users()->attach($login, ['role' => 'employee']);
    $hidden->users()->attach($login, ['role' => 'employee']);
    chainEmployee($cafe, 'Anna', $login);
    chainEmployee($hidden, 'Anna', $login);
    chainShift($cafe, 'Anna', '2026-11-03', '08:00', '16:00');
    chainShift($hidden, 'Anna', '2026-11-03', '15:00', '20:00');

    $data = app(ChainSchedule::class)->for($this->viewer->fresh(), $this->monday);

    expect($data['conflicts'])->toBe([])
        ->and(collect($data['companies'])->flatMap(fn (array $block): array => $block['chips'])->flatten(1)->pluck('warnings')->flatten()->all())->toBe([]);

    $this->actingAs($this->viewer->fresh());
    Livewire::test(ChainScheduleComponent::class)->assertDontSee('Dolda Bolaget AB')->assertDontSee('Krockar mellan företag: 1');
});

it('writes nothing: no shift, no setting and no audit entry changes when the page is read', function () {
    $cafe = chainCompany('Café Hamnen AB');
    chainEmployee($cafe, 'Anna');
    chainShift($cafe, 'Anna', '2026-11-03', '08:00', '16:00');
    $this->actingAs($this->viewer->fresh());
    $before = [Shift::query()->withoutGlobalScopes()->count(), AuditLog::query()->count(), Shift::query()->withoutGlobalScopes()->max('updated_at')];

    Livewire::test(ChainScheduleComponent::class)->call('next')->call('previous')->call('today');

    expect([Shift::query()->withoutGlobalScopes()->count(), AuditLog::query()->count(), Shift::query()->withoutGlobalScopes()->max('updated_at')])->toBe($before)
        ->and(get_class_methods(ChainScheduleComponent::class))->not->toContain('save', 'delete', 'move', 'publish');
});

it('opens a shift in its own company through a logged switch, and refuses a company that is not the user\'s', function () {
    $cafe = chainCompany('Café Hamnen AB');
    $bistro = chainCompany('Bistro Torget AB');
    $stranger = Bookkeeping::company()[1];
    $this->viewer->switchCompany($cafe);

    $this->actingAs($this->viewer)
        ->put(route('current-team.update'), ['team_id' => $bistro->id, 'till' => 'planning', 'vecka' => '2026-11-02', 'dag' => '2026-11-03'])
        ->assertRedirect(route('planning.schedule', ['vy' => 'dag', 'dag' => '2026-11-03']));

    expect($this->viewer->fresh()->current_team_id)->toBe($bistro->id)
        ->and(AuditLog::query()->where('action', 'company.switched')->where('company_id', $bistro->id)->exists())->toBeTrue();

    $this->actingAs($this->viewer)
        ->put(route('current-team.update'), ['team_id' => $cafe->id, 'till' => 'planning', 'vecka' => '2026-11-02'])
        ->assertRedirect(route('planning.schedule', ['vecka' => '2026-11-02']));

    $this->actingAs($this->viewer)
        ->put(route('current-team.update'), ['team_id' => $stranger->id, 'till' => 'planning'])
        ->assertNotFound();

    $this->actingAs($this->viewer)
        ->put(route('current-team.update'), ['team_id' => $cafe->id, 'till' => 'planning', 'vecka' => 'inte-ett-datum', 'dag' => '2026-13-45'])
        ->assertRedirect(route('planning.schedule'));
});

it('answers the page route for a signed-in user who may see a schedule', function () {
    chainCompany('Café Hamnen AB');
    chainCompany('Bistro Torget AB');
    $viewer = $this->viewer->fresh();
    $viewer->switchCompany($viewer->companies()->first());

    $this->actingAs($viewer->fresh())->get(route('companies.schedule'))->assertOk()->assertSee('Schema över företag');
});

it('reads a fixed number of queries whatever the number of companies and shifts', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ChainSchedule::class)->for($this->viewer->fresh(), $this->monday);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $login = User::factory()->create();
    $first = chainCompany('Första AB');
    $first->users()->attach($login, ['role' => 'employee']);
    chainEmployee($first, 'Anna', $login);
    chainShift($first, 'Anna', '2026-11-03', '08:00', '12:00');
    $few = $count();

    foreach (range(1, 6) as $number) {
        $company = chainCompany("Kedjan {$number} AB");
        $company->users()->attach($login, ['role' => 'employee']);
        chainEmployee($company, 'Anna', $login);

        foreach (['2026-11-02', '2026-11-04', '2026-11-05'] as $date) {
            chainShift($company, 'Anna', $date, '13:00', '17:00');
        }
    }

    expect($count())->toBe($few);
});
