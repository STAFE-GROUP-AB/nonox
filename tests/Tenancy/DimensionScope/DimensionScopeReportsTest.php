<?php

declare(strict_types=1);

use App\Livewire\Accounting\Reports;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\DimensionScopeFixture;

/*
 * ADR-081 gap 10: a limited member reads ledger reports only with one of their
 * own cost centres or projects as filter; the whole-company reports
 * (saldobalans, balance sheet, voucher list, general ledger without a filter,
 * VAT, SIE) are refused.
 */
beforeEach(function () {
    $this->fixture = DimensionScopeFixture::make();
    $this->member = $this->fixture->member();
    $this->actingAs($this->member);
    CurrentCompany::forget();
});

it('turns a whole-company report into the unit report for the member\'s own unit', function (string $report) {
    $page = Livewire::test(Reports::class, ['rapport' => $report]);
    $filters = $page->viewData('filters');

    expect($filters->report)->toBe('resultat')
        ->and($filters->costCenter?->code)->toBe('10')
        ->and($page->viewData('costCenters')->pluck('code')->all())->toBe(['10'])
        ->and($page->viewData('projects')->pluck('code')->all())->toBe(['P1']);
})->with(['saldobalans', 'balans', 'verifikationer']);

it('never shows another unit\'s figures, even when asked for by code', function () {
    $page = Livewire::test(Reports::class, ['rapport' => 'resultat', 'kst' => '20']);
    $data = $page->viewData('data');

    expect($page->viewData('filters')->costCenter?->code)->toBe('10')
        // Only cost centre 10's revenue (500 kr), and its half of the shared rent (300 kr).
        ->and($data['result'])->toBe(50_000 - 30_000);
});

it('reads a project report for a project in the scope', function () {
    $page = Livewire::test(Reports::class, ['rapport' => 'huvudbok', 'projekt' => 'P1']);

    expect($page->viewData('filters')->project?->code)->toBe('P1')
        ->and(collect($page->viewData('data'))->pluck('number')->all())->toBe([4010]);
});

it('downloads only a unit report for one of the member\'s units', function () {
    $this->get(route('accounting.reports.csv', ['rapport' => 'resultat', 'kst' => '10']))->assertOk();
    $this->get(route('accounting.reports.csv', ['rapport' => 'resultat', 'kst' => '20']))->assertNotFound();
    $this->get(route('accounting.reports.csv', ['rapport' => 'saldobalans', 'kst' => '10']))->assertNotFound();
    $this->get(route('accounting.reports.csv', ['rapport' => 'balans']))->assertNotFound();
    $this->get(route('accounting.reports.csv', ['rapport' => 'huvudbok']))->assertNotFound();
});

it('shows a unit manager without units an explanation instead of figures', function () {
    $manager = Bookkeeping::member($this->fixture->company, 'unit_manager');
    $this->actingAs($manager);

    Livewire::test(Reports::class)->assertViewIs('livewire.accounting.reports-empty')->assertSee('Inga enheter ännu');
});

it('leaves the reports of an unlimited member as they were', function () {
    $this->actingAs($this->fixture->owner);

    $page = Livewire::test(Reports::class, ['rapport' => 'saldobalans']);

    expect($page->viewData('filters')->report)->toBe('saldobalans')
        ->and($page->viewData('filters')->costCenter)->toBeNull();
});

it('shows only the row\'s own text, and no link, for a voucher the member may not open', function () {
    $ledger = collect(Livewire::test(Reports::class, ['rapport' => 'huvudbok', 'kst' => '10'])->viewData('data'));
    $rent = collect($ledger->firstWhere('number', 5010)['rows'])->firstWhere('voucher_id', $this->fixture->vouchers['mixed']->id);
    $sale = collect($ledger->firstWhere('number', 3001)['rows'])->firstWhere('voucher_id', $this->fixture->vouchers['inside']->id);

    expect($rent['linked'])->toBeFalse()
        ->and($rent['description'])->toBe('')
        ->and($sale['linked'])->toBeTrue()
        ->and($sale['description'])->toBe('Kassa norr');

    Livewire::test(Reports::class, ['rapport' => 'huvudbok', 'kst' => '10'])
        ->assertDontSee('Hyra delad')
        ->assertDontSee(route('accounting.vouchers.show', $this->fixture->vouchers['mixed']), false)
        ->assertSee(route('accounting.vouchers.show', $this->fixture->vouchers['inside']), false);

    $this->get(route('accounting.reports.csv', ['rapport' => 'huvudbok', 'kst' => '10']))->assertOk()->assertDontSee('Hyra delad');
});
