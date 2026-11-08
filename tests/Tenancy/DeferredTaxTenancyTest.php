<?php

declare(strict_types=1);

use App\Actions\Closing\DeferredTaxItems;
use App\Actions\Closing\YearEndClosing;
use App\Domain\Closing\DeferredTaxProposal;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Livewire\Accounting\DeferredTaxPage;
use App\Models\DeferredTaxItem;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for deferred tax items (ADR-059, A.2.6, A.5): company A
 * never sees B's items, through Eloquent, the proposal, the actions or the
 * page; B's ids answer 404.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));

    foreach (['a' => 'Byggnad A', 'b' => 'Hemlig byggnad B'] as $side => $description) {
        [$owner, $company] = Bookkeeping::company();
        $company->forceFill(['legal_form' => LegalForm::Ab])->save();
        $this->actingAs($owner);
        CurrentCompany::set($company);
        $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
        $year->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
        $item = app(DeferredTaxItems::class)->save($owner, $year->refresh(), [
            'kind' => DeferredTaxItem::OTHER_ASSET, 'description' => $description, 'carrying' => '100 000', 'tax_value' => '40 000',
        ]);
        $this->{$side} = compact('owner', 'company', 'year', 'item');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('never shows B\'s items through Eloquent or the proposal', function () {
    expect(DeferredTaxItem::query()->pluck('id')->all())->toBe([$this->a['item']->id]);

    // Even B's fiscal year read in A's context finds none of B's items.
    $proposal = DeferredTaxProposal::for($this->a['company'], $this->a['year']);

    expect(array_map(fn (array $line): int => $line['item']->id, $proposal->lines))->toBe([$this->a['item']->id])
        ->and($proposal->liabilityOre())->toBe(1_236_000);
});

it('answers 404 for B\'s item in the actions and the page', function () {
    $items = app(DeferredTaxItems::class);

    expect(fn () => $items->save($this->a['owner'], $this->a['year'], ['id' => $this->b['item']->id, 'kind' => DeferredTaxItem::OTHER_ASSET, 'description' => 'x', 'carrying' => '1', 'tax_value' => '1']))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $items->remove($this->a['owner'], $this->a['year'], $this->b['item']->id))->toThrow(ModelNotFoundException::class);

    Livewire::test(DeferredTaxPage::class)->call('edit', $this->b['item']->id)->assertNotFound();
    $this->get(route('accounting.closing.deferredTax', ['ar' => $this->b['year']->id]))->assertOk()->assertDontSee('Hemlig byggnad B');
});

it('refuses A\'s user on B\'s fiscal year', function () {
    expect(fn () => app(DeferredTaxItems::class)->save($this->a['owner'], $this->b['year'], ['kind' => DeferredTaxItem::OTHER_ASSET, 'description' => 'x', 'carrying' => '1', 'tax_value' => '1']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(YearEndClosing::class)->confirmDeferredTax($this->a['owner'], $this->b['year'], 0))->toThrow(AuthorizationException::class);
});
