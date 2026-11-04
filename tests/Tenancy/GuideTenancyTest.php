<?php

declare(strict_types=1);

use App\Actions\Guide\CompleteGuideStep;
use App\Actions\Guide\UncompleteGuideStep;
use App\Livewire\Guide\StartPlan;
use App\Models\CompanyGuideStep;
use App\Models\User;
use App\Support\Guide\Guide;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Företagsguiden's progress (CompanyGuideStep, ADR-051) belongs to one
 * company: another company's ticked steps are never shown, counted or
 * changed, and a start plan page kept open after switching company answers
 * 404.
 */

beforeEach(function () {
    config([
        'app.guide.path' => base_path('tests/Fixtures/foretagsguiden/sv'),
        'app.guide.start_plan' => base_path('tests/Fixtures/foretagsguiden/startplan.json'),
    ]);
    Guide::flush();

    [$this->ownerA, $this->companyA] = Bookkeeping::company();
    [$this->ownerB, $this->companyB] = Bookkeeping::company();
    app(CompleteGuideStep::class)($this->ownerB, $this->companyB, 'registrera-foretaget');
});

it('never shows or counts another company\'s progress', function () {
    CurrentCompany::set($this->companyA);

    expect(CompanyGuideStep::query()->count())->toBe(0);

    $this->actingAs($this->ownerA)->get(route('guide.plan'))->assertOk()
        ->assertSee('0 av ')
        ->assertDontSee('Klart ');
});

it('ticks only in the current company, and refuses another company\'s plan', function () {
    $this->actingAs($this->ownerA);
    CurrentCompany::set($this->companyA);

    Livewire::test(StartPlan::class)->call('toggle', 'registrera-foretaget', false);

    expect(CurrentCompany::run($this->companyB, fn (): int => CompanyGuideStep::query()->count()))->toBe(1)
        ->and(fn () => app(UncompleteGuideStep::class)($this->ownerA, $this->companyB, 'registrera-foretaget'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CompleteGuideStep::class)($this->ownerA, $this->companyB, 'betala-in-aktiekapitalet'))->toThrow(AuthorizationException::class);

    Livewire::test(StartPlan::class)->call('toggle', 'betala-in-aktiekapitalet', true);

    expect(CompanyGuideStep::query()->pluck('step_key')->all())->toBe(['betala-in-aktiekapitalet'])
        ->and(CurrentCompany::run($this->companyB, fn (): array => CompanyGuideStep::query()->pluck('step_key')->all()))->toBe(['registrera-foretaget']);
});

it('answers 404 to a start plan page kept open after switching company', function () {
    $this->companyB->users()->attach($this->ownerA, ['role' => 'admin']);
    CurrentCompany::set($this->companyA);
    $this->actingAs($this->ownerA);

    $page = Livewire::test(StartPlan::class)->assertOk();
    $this->ownerA->switchCompany($this->companyB);

    $page->call('toggle', 'registrera-foretaget', false)->assertNotFound();

    expect(CurrentCompany::run($this->companyB, fn (): int => CompanyGuideStep::query()->count()))->toBe(1);
});

it('gives a user without a company no start plan', function () {
    $this->actingAs(User::factory()->create())->get(route('guide.plan'))->assertRedirect(route('onboarding'));
});
