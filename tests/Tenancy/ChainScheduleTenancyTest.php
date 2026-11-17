<?php

declare(strict_types=1);

use App\Domain\Firm\ChainSchedule;
use App\Livewire\Firm\ChainSchedule as ChainScheduleComponent;
use App\Models\SavedView;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Planning\PlanningModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;

/*
 * AGENTS.md §13.2 for the chain view (ADR-037 amendment, gap 8): a company the
 * user is not a member of never appears, whatever is asked for, saved or
 * linked; and inside the user's own companies only those where the user holds
 * planning:view are read.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'Europe/Stockholm'));
    $this->monday = CarbonImmutable::parse('2026-11-02', 'Europe/Stockholm');

    [$this->ownerA, $this->a] = Bookkeeping::company();
    [$this->ownerB, $this->b] = Bookkeeping::company();
    $this->a->forceFill(['name' => 'Alfa Kedja AB'])->save();
    $this->b->forceFill(['name' => 'Beta Främmande AB'])->save();
    Planning::setUp($this->a, $this->ownerA);
    app(ModuleManager::class)->enable(PayrollModule::KEY, $this->b, $this->ownerB);
    app(ModuleManager::class)->enable(PlanningModule::KEY, $this->b, $this->ownerB);

    $this->annaA = Planning::employee($this->ownerA, $this->a, 'Anna');
    $this->bertilB = Planning::employee($this->ownerB, $this->b, 'Bertil', '900615123');
    Planning::shift($this->ownerA, $this->a, ['employee_id' => $this->annaA->id, 'date' => '2026-11-03', 'start' => '08:00', 'end' => '12:00']);
    Planning::shift($this->ownerB, $this->b, ['employee_id' => $this->bertilB->id, 'date' => '2026-11-03', 'start' => '13:00', 'end' => '17:00']);
    CurrentCompany::forget();

    // A scheduler in A, no member of B.
    $this->userA = Bookkeeping::member($this->a, 'scheduler');
});

it('never shows a company the user is not a member of, whatever is asked for', function () {
    $data = app(ChainSchedule::class)->for($this->userA, $this->monday, [$this->b->id, $this->a->id]);

    expect($data['selected'])->toBe([$this->a->id])
        ->and(array_keys($data['eligible']))->toBe([$this->a->id])
        ->and(json_encode($data))->not->toContain('Beta Främmande AB')->not->toContain('Bertil');

    $this->actingAs($this->userA);
    Livewire::withQueryParams(['foretag' => [(string) $this->b->id]])->test(ChainScheduleComponent::class)
        ->assertSee('Alfa Kedja AB')
        ->assertDontSee('Beta Främmande AB')
        ->assertDontSee('Bertil');
});

it('ignores a saved view that names a company the user is not a member of', function () {
    SavedView::query()->create(['user_id' => $this->userA->id, 'page' => 'planning-chain', 'name' => 'Med främmande', 'filters' => ['foretag' => $this->b->id.','.$this->a->id]]);
    $this->actingAs($this->userA);

    Livewire::test(ChainScheduleComponent::class)
        ->call('applyView', SavedView::query()->where('name', 'Med främmande')->value('id'))
        ->assertSee('Alfa Kedja AB')
        ->assertDontSee('Beta Främmande AB')
        ->assertDontSee('Bertil');
});

it('reads only the companies where the user holds planning:view, not those where the user is a plain employee', function () {
    $this->b->users()->attach($this->userA, ['role' => 'employee']);
    $user = User::query()->findOrFail($this->userA->id);

    $data = app(ChainSchedule::class)->for($user, $this->monday);

    expect(array_keys($data['eligible']))->toBe([$this->a->id])
        ->and(json_encode($data))->not->toContain('Bertil');

    $this->b->users()->updateExistingPivot($this->userA->id, ['role' => 'scheduler']);
    $both = app(ChainSchedule::class)->for(User::query()->findOrFail($this->userA->id), $this->monday);

    expect(array_keys($both['eligible']))->toEqualCanonicalizing([$this->a->id, $this->b->id])
        ->and(json_encode($both))->toContain('Bertil');
});

it('does not reach another company through the open-in-company switch', function () {
    $this->actingAs($this->userA)
        ->put(route('current-team.update'), ['team_id' => $this->b->id, 'till' => 'planning', 'dag' => '2026-11-03'])
        ->assertNotFound();

    expect(Employee::query()->withoutGlobalScopes()->where('company_id', $this->b->id)->exists())->toBeTrue()
        ->and($this->userA->fresh()->current_team_id)->toBe($this->a->id);
});
