<?php

declare(strict_types=1);

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDimensionObject;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Accounting\Dimension;
use App\Livewire\Projects\ProjectList;
use App\Livewire\Projects\ProjectResult;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for the project results (ADR-081 E7): company A never sees
 * B's projects or their figures, and B's project id answers 404.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    foreach (['a' => 1_000_000, 'b' => 7_000_000] as $side => $amount) {
        [$owner, $company] = Bookkeeping::company();
        CurrentCompany::set($company);
        $project = app(SaveDimensionObject::class)($owner, $company, Dimension::Project, ['code' => 'P1', 'name' => "Hemligt projekt {$side}"]);
        app(PostVoucher::class)($owner, app(SaveDraftVoucher::class)($owner, $company, [
            'voucher_date' => '2026-03-01', 'description' => "Försäljning {$side}", 'series' => 'A',
            'rows' => [['account' => 1510, 'debit_ore' => $amount], ['account' => 3001, 'credit_ore' => $amount, 'project' => 'P1', 'description' => "Rad {$side}"]],
        ]));
        $this->{$side} = compact('owner', 'company', 'project');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('answers 404 for another company\'s project', function () {
    $this->get(route('projects.show', $this->a['project']))->assertOk();
    $this->get(route('projects.show', $this->b['project']))->assertNotFound();
});

it('lists and sums only the company\'s own projects, also with the same project code', function () {
    $page = Livewire::test(ProjectList::class)->assertSee('Hemligt projekt a')->assertDontSee('Hemligt projekt b');

    expect(array_keys($page->viewData('results')))->toBe([$this->a['project']->id])
        ->and($page->viewData('results')[$this->a['project']->id]['actual']['revenue'])->toBe(1_000_000);
});

it('drills down only into the company\'s own rows', function () {
    $drill = Livewire::test(ProjectResult::class, ['project' => $this->a['project']])->call('drill', 3001)->viewData('drill');

    expect(array_column($drill['rows'], 'text'))->toBe(['Rad a'])
        ->and(array_column($drill['rows'], 'amount'))->toBe([1_000_000]);
});
