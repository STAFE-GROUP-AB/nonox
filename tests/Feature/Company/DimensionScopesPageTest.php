<?php

declare(strict_types=1);

use App\Livewire\Company\DimensionScopes;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\DimensionScopeFixture;

/*
 * "Behörighet per enhet" under the company's settings (ADR-081 gap 10).
 */
beforeEach(function () {
    $this->fixture = DimensionScopeFixture::make();
    $this->actingAs($this->fixture->owner);
});

it('shows the tab to the owner and admins only', function () {
    $this->get(route('teams.show', ['company' => $this->fixture->company->id, 'flik' => 'enheter']))
        ->assertOk()
        ->assertSee('Behörighet per enhet');

    $this->actingAs(Bookkeeping::member($this->fixture->company, 'accountant'))
        ->get(route('teams.show', ['company' => $this->fixture->company->id, 'flik' => 'enheter']))
        ->assertOk()
        ->assertDontSee('Behörighet per enhet');
});

it('lists only members whose role can be limited', function () {
    $bookkeeper = Bookkeeping::member($this->fixture->company, 'bookkeeper');
    $manager = Bookkeeping::member($this->fixture->company, 'unit_manager');
    Bookkeeping::member($this->fixture->company, 'accountant');
    Bookkeeping::member($this->fixture->company, 'auditor');
    Bookkeeping::member($this->fixture->company, 'admin');

    $members = Livewire::test(DimensionScopes::class, ['company' => $this->fixture->company])->viewData('members');

    expect($members->pluck('user_id')->sort()->values()->all())->toBe(collect([$bookkeeper->id, $manager->id])->sort()->values()->all())
        ->and($members->firstWhere('user_id', $manager->id)['limited'])->toBeTrue()
        ->and($members->firstWhere('user_id', $bookkeeper->id)['limited'])->toBeFalse();
});

it('limits and lifts with a toast each', function () {
    $member = Bookkeeping::member($this->fixture->company, 'bookkeeper');

    Livewire::test(DimensionScopes::class, ['company' => $this->fixture->company])
        ->call('edit', $member->id)
        ->set('costCenterIds', [(string) $this->fixture->north->id])
        ->call('save')
        ->assertDispatched('toast', message: 'Behörigheten är sparad. Personen når nu bara de valda kostnadsställena och projekten.')
        ->assertSet('editing', null)
        ->call('lift', $member->id)
        ->assertDispatched('toast', message: 'Begränsningen är borttagen.');

    DimensionScope::forget();

    expect(DimensionScope::for($member->fresh(), $this->fixture->company)->restricted)->toBeFalse();
});

it('saves a scope that shows in the list', function () {
    $member = Bookkeeping::member($this->fixture->company, 'bookkeeper');

    Livewire::test(DimensionScopes::class, ['company' => $this->fixture->company])
        ->call('edit', $member->id)
        ->set('costCenterIds', [(string) $this->fixture->north->id])
        ->set('projectIds', [(string) $this->fixture->rebuild->id])
        ->call('save')
        ->assertSee('Når bara: 10 Butik Norr, P1 Ombyggnad');
});

it('refuses those who may not set scopes', function () {
    $this->actingAs(Bookkeeping::member($this->fixture->company, 'accountant'));

    Livewire::test(DimensionScopes::class, ['company' => $this->fixture->company])->assertForbidden();
});

it('answers 404 for a member of another company', function () {
    $other = DimensionScopeFixture::make();
    $stranger = Bookkeeping::member($other->company, 'bookkeeper');

    Livewire::test(DimensionScopes::class, ['company' => $this->fixture->company])->call('edit', $stranger->id)->assertNotFound();
});

it('renders in the same number of queries for few and many people', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(DimensionScopes::class, ['company' => $this->fixture->company]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $this->fixture->member();
    $count();
    $short = $count();

    foreach (range(1, 6) as $index) {
        $this->fixture->member($index % 2 === 0 ? 'bookkeeper' : 'unit_manager');
    }

    $this->actingAs($this->fixture->owner);
    $count();

    expect($count())->toBe($short);
});
