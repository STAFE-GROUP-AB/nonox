<?php

declare(strict_types=1);

use App\Actions\AnnualReport\K3TransitionItems;
use App\Domain\AnnualReport\K3TransitionProposal;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Models\FiscalYear;
use App\Models\K3TransitionItem;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

/*
 * S1-R7.3, K3 35.27 and 35.31: the mechanism of the relief for the opening
 * deferred tax, on a bare synthetic company. This is NOT a realistic company
 * case: it only proves that NONOX requires a reason, discloses the relief and
 * books nothing for the item. Whether 35.27 applies to real facts ("om
 * uppgifterna inte kan tas fram utan oskälig kostnad") is the person's
 * judgement. Fixture F-S1-b uses no relief.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['legal_form' => LegalForm::Ab])->save();
    $this->actingAs($this->owner);
    CurrentCompany::set($this->company);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $this->year->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    $this->year->refresh();
    $this->input = ['kind' => 'DEFERRED_TAX', 'deferred_tax_kind' => 'OTHER_ASSET', 'description' => 'Testpost med lättnadsregel', 'carrying' => '100 000', 'relief' => true];
});

it('S1-R7.3 refuses the 35.27 relief without a reason', function () {
    expect(fn () => app(K3TransitionItems::class)->save($this->owner, $this->year, $this->input))
        ->toThrow(ValidationException::class, 'oskälig kostnad');
    expect(fn () => app(K3TransitionItems::class)->save($this->owner, $this->year, [...$this->input, 'relief_reason' => '   ']))
        ->toThrow(ValidationException::class, 'oskälig kostnad');

    expect(K3TransitionItem::query()->count())->toBe(0);
});

it('S1-R7.3 S1-R7.5 discloses the relief with the item and books nothing for it', function () {
    $item = app(K3TransitionItems::class)->save($this->owner, $this->year, [...$this->input, 'tax_value' => '40 000', 'recognised' => '5 000', 'relief_reason' => 'Skälet anges här.']);
    $proposal = K3TransitionProposal::for($this->company, $this->year);
    $line = $proposal->lines[0];

    expect($item->relief_35_27)->toBeTrue()
        // A relief item keeps no tax value and no recognised asset.
        ->and($item->tax_value_ore)->toBeNull()
        ->and($item->recognised_ore->ore)->toBe(0)
        ->and($line['nature'])->toBe(K3TransitionProposal::RELIEF)
        ->and($line['equity_ore'])->toBe(0)
        ->and($line['rows'])->toBe([])
        ->and($proposal->equityEffectOre())->toBe(0)
        ->and($proposal->voucherRows())->toBe([])
        ->and($proposal->disclosures()['reliefs'])->toBe(['K3 35.27'])
        ->and($proposal->disclosures()['relief_items'])->toBe(['Testpost med lättnadsregel'])
        ->and(implode(' ', $proposal->comparabilityNote()))
        ->toContain('Lättnadsregeln i K3 punkt 35.27 har tillämpats')
        ->toContain('Testpost med lättnadsregel')
        ->toContain('Övergången har inte påverkat eget kapital.');
});
