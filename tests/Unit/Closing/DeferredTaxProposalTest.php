<?php

declare(strict_types=1);

use App\Domain\Closing\DeferredTaxProposal;
use App\Models\DeferredTaxItem;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;

/*
 * The arithmetic of the deferred tax proposal (ADR-059 A.2.6, A.5), without
 * the ledger: the table in the K3 29.14 comment, the tax on a difference,
 * the exclusion of untaxed reserves and the rate of the year after the
 * balance date.
 */

it('S1-R4.1 classifies a difference by the table in the K3 29.14 comment', function (string $kind, int $carrying, int $tax, string $nature) {
    expect(DeferredTaxProposal::nature($kind, $carrying, $tax))->toBe($nature);
})->with([
    'asset, carrying above tax value: taxable' => [DeferredTaxItem::OTHER_ASSET, 1_000, 600, DeferredTaxProposal::TAXABLE],
    'asset, carrying below tax value: deductible' => [DeferredTaxItem::OTHER_ASSET, 600, 1_000, DeferredTaxProposal::DEDUCTIBLE],
    'building, carrying above tax value: taxable' => [DeferredTaxItem::BUILDING_COMPONENTS, 1_719_600_000, 1_236_480_000, DeferredTaxProposal::TAXABLE],
    'liability, carrying above tax value: deductible' => [DeferredTaxItem::OTHER_LIABILITY, 1_000, 600, DeferredTaxProposal::DEDUCTIBLE],
    'liability, carrying below tax value: taxable' => [DeferredTaxItem::OTHER_LIABILITY, 600, 1_000, DeferredTaxProposal::TAXABLE],
    'equal values: no difference' => [DeferredTaxItem::OTHER_ASSET, 1_000, 1_000, DeferredTaxProposal::NONE],
    'loss carry-forward: deductible' => [DeferredTaxItem::LOSS_CARRY_FORWARD, 0, 50_000_000, DeferredTaxProposal::DEDUCTIBLE],
]);

it('S1-R4.4 takes the difference times the rate, half up to the öre, never discounted', function () {
    // F-S1-a: 4 831 200 kr × 20,6 % = 995 227,20 kr exactly.
    expect(DeferredTaxProposal::deferredTax(483_120_000, 2060))->toBe(99_522_720)
        ->and(DeferredTaxProposal::deferredTax(-483_120_000, 2060))->toBe(99_522_720)
        // 0,25 kr × 20,6 % = 5,15 öre → 5; 0,75 kr → 15,45 → 15; exactly half an öre → up.
        ->and(DeferredTaxProposal::deferredTax(25, 2060))->toBe(5)
        ->and(DeferredTaxProposal::deferredTax(75, 2060))->toBe(15)
        ->and(DeferredTaxProposal::deferredTax(1, 5000))->toBe(1)
        ->and(DeferredTaxProposal::deferredTax(1_250, 2))->toBe(0);
});

it('S1-R4.2 c recognises the untaxed reserves 2100–2199 in any account range that touches them', function () {
    expect(DeferredTaxProposal::touchesUntaxedReserves(2100, 2100))->toBeTrue()
        ->and(DeferredTaxProposal::touchesUntaxedReserves(2124, 2126))->toBeTrue()
        ->and(DeferredTaxProposal::touchesUntaxedReserves(2000, 2999))->toBeTrue()
        ->and(DeferredTaxProposal::touchesUntaxedReserves(2199, 2300))->toBeTrue()
        ->and(DeferredTaxProposal::touchesUntaxedReserves(2200, 2299))->toBeFalse()
        ->and(DeferredTaxProposal::touchesUntaxedReserves(1110, 1119))->toBeFalse();
});

it('S1-R4.3 reads the rate of the beskattningsår that begins the day after the balance date', function () {
    $year = fn (string $start, string $end): FiscalYear => new FiscalYear(['start_date' => CarbonImmutable::parse($start), 'end_date' => CarbonImmutable::parse($end)]);

    // The year itself began in 2020 (21,4 %), but the differences reverse from 2021 on (20,6 %).
    expect(DeferredTaxProposal::rateFor($year('2020-01-01', '2020-12-31')))->toBe(2060)
        ->and(DeferredTaxProposal::rateFor($year('2025-01-01', '2025-12-31')))->toBe(2060)
        ->and(DeferredTaxProposal::rateFor($year('2025-07-01', '2026-06-30')))->toBe(2060);

    // 2020 has 21,4 % and later years 20,6 %: which differences reverse when is a human decision.
    expect(DeferredTaxProposal::rateFor($year('2019-01-01', '2019-12-31')))->toBeString()->toContain('Beräkna den uppskjutna skatten för hand');
});

it('formats a rate in Swedish', function () {
    expect(DeferredTaxProposal::percent(2060))->toBe('20,6 %')
        ->and(DeferredTaxProposal::percent(2140))->toBe('21,4 %')
        ->and(DeferredTaxProposal::percent(2000))->toBe('20 %');
});
