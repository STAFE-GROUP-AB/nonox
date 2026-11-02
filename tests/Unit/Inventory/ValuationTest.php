<?php

declare(strict_types=1);

use App\Modules\Inventory\Domain\ArticleValuation;
use App\Modules\Inventory\Domain\Gtin;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\ValuationMethod;

/*
 * Lager's valuation, worked by hand (ADR-039). The movements:
 *   in 10 st à 100 kr, in 10 st à 120 kr, out 15 st, in 5 st à 130 kr.
 *
 * FIFO, after the issue (5 on hand): the 5 received last, à 120 = 600 kr.
 *   After the last receipt (10 on hand): 5 à 130 + 5 à 120 = 1 250 kr.
 * Weighted average: 20 st for 2 200 kr (110 kr/st); the issue takes
 *   15 × 110 = 1 650 kr, leaving 550 kr. The last receipt adds 650 kr:
 *   10 st for 1 200 kr.
 */

function stockMovementRow(int $quantity, ?int $kronor = null, string $type = 'RECEIPT'): array
{
    return ['quantity_milli' => $quantity * 1000, 'unit_cost_ore' => $kronor === null ? null : $kronor * 100, 'type' => $type];
}

dataset('ledger', [[[stockMovementRow(10, 100), stockMovementRow(10, 120), stockMovementRow(-15, null, 'PICK')]]]);

it('values the goods on hand at the latest receipts with FIFO', function (array $movements) {
    $valuation = ArticleValuation::of($movements, ValuationMethod::Fifo);
    expect($valuation->quantityMilli)->toBe(5000)->and($valuation->valueOre)->toBe(60000);

    $valuation = ArticleValuation::of([...$movements, stockMovementRow(5, 130)], ValuationMethod::Fifo);
    expect($valuation->quantityMilli)->toBe(10000)
        ->and($valuation->costValueOre)->toBe(125000)
        ->and($valuation->unitCostOre())->toBe(12500);
})->with('ledger');

it('values the goods on hand at the moving weighted average', function (array $movements) {
    expect(ArticleValuation::of($movements, ValuationMethod::Average)->valueOre)->toBe(55000);

    $valuation = ArticleValuation::of([...$movements, stockMovementRow(5, 130)], ValuationMethod::Average);
    expect($valuation->costValueOre)->toBe(120000)->and($valuation->unitCostOre())->toBe(12000);
})->with('ledger');

it('writes down to the net realisable value only when it is lower (lägsta värdets princip)', function (array $movements) {
    $all = [...$movements, stockMovementRow(5, 130)];

    $lower = ArticleValuation::of($all, ValuationMethod::Fifo, netRealisableUnitOre: 10000);
    expect($lower->costValueOre)->toBe(125000)->and($lower->valueOre)->toBe(100000)->and($lower->writtenDown())->toBeTrue();

    $higher = ArticleValuation::of($all, ValuationMethod::Fifo, netRealisableUnitOre: 20000);
    expect($higher->valueOre)->toBe(125000)->and($higher->writtenDown())->toBeFalse();
})->with('ledger');

it('ignores moves between locations and values nothing below zero', function () {
    $moves = [stockMovementRow(4, 50), stockMovementRow(-4, null, 'TRANSFER'), stockMovementRow(4, null, 'TRANSFER')];
    expect(ArticleValuation::of($moves, ValuationMethod::Fifo)->valueOre)->toBe(20000)
        ->and(ArticleValuation::of($moves, ValuationMethod::Average)->valueOre)->toBe(20000);

    $negative = [stockMovementRow(2, 50), stockMovementRow(-5, null, 'PICK')];
    expect(ArticleValuation::of($negative, ValuationMethod::Fifo)->valueOre)->toBe(0)
        ->and(ArticleValuation::of($negative, ValuationMethod::Average)->valueOre)->toBe(0);

    // Below zero, a receipt first fills the hole: 4 in at 60 kr after −3 leaves 1 at 60 kr.
    expect(ArticleValuation::of([...$negative, stockMovementRow(4, 60)], ValuationMethod::Average)->valueOre)->toBe(6000)
        ->and(ArticleValuation::of([...$negative, stockMovementRow(4, 60)], ValuationMethod::Fifo)->valueOre)->toBe(6000);
});

it('values decimals of a unit to whole öre, rounded half up', function () {
    // 1,5 kg à 33,33 kr = 49,995 kr → 50,00 kr.
    expect(Quantity::value(1500, 3333))->toBe(5000)
        ->and(ArticleValuation::of([['quantity_milli' => 1500, 'unit_cost_ore' => 3333, 'type' => 'RECEIPT']], ValuationMethod::Fifo)->valueOre)->toBe(5000);
});

it('parses and writes quantities in thousandths', function () {
    expect(Quantity::parse('3'))->toBe(3000)
        ->and(Quantity::parse('1,5'))->toBe(1500)
        ->and(Quantity::parse('1 234,250'))->toBe(1234250)
        ->and(Quantity::parse('0.125'))->toBe(125)
        ->and(Quantity::format(1500))->toBe('1,5')
        ->and(Quantity::format(-2000))->toBe('−2')
        ->and(Quantity::decimal(1234250))->toBe('1234.25')
        ->and(Quantity::decimal(-500))->toBe('-0.5');

    expect(fn () => Quantity::parse('1,2345'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Quantity::parse('tio'))->toThrow(InvalidArgumentException::class);
});

it('checks the GTIN check digit', function () {
    expect(Gtin::isValid('7310865004703'))->toBeTrue()
        ->and(Gtin::isValid('7310865004704'))->toBeFalse()
        ->and(Gtin::isValid('96385074'))->toBeTrue()
        ->and(Gtin::isValid('12345'))->toBeFalse()
        ->and(Gtin::checkDigit('731086500470'))->toBe(3);
});
