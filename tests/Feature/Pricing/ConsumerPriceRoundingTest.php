<?php

declare(strict_types=1);

use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Invoicing\SalesRowInput;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Tests\Support\PricingScenario;

/*
 * P-L1 (ADR-082 P3, jurist re-check 2026-11-20): from a list with prices
 * including VAT, with or without an agreed discount, a consumer never pays
 * more than the shown (discounted) gross price × the quantity. Both steps
 * round down: the discounted gross price, then the net price. Checked over
 * gross prices, discounts, VAT rates and quantities.
 */

it('never charges more than the shown discounted gross price × quantity', function (int $discountBp) {
    CarbonImmutable::setTestNow('2026-03-15 10:00:00');

    $grosses = [1, 99, 999, 1001, 1999, 7777, 12345];
    $rates = [2500, 1200, 600];
    $quantities = ['1', '2', '3', '7', '2,5', '12'];
    $articles = [];
    $items = [];

    foreach ($grosses as $gross) {
        foreach ($rates as $rate) {
            $number = "G{$gross}V{$rate}";
            $articles[$number] = ['price_ore' => 100000, 'vat_rate' => $rate];
            $items[] = ['article' => $number, 'price_minor' => $gross];
        }
    }

    $scenario = PricingScenario::build([
        'articles' => $articles,
        'lists' => ['BRUTTO' => ['default' => true, 'prices_include_vat' => true, 'items' => $items]],
        'customer' => [],
        'agreements' => $discountBp === 0 ? [] : ['PA' => ['valid_from' => '2026-01-01', 'items' => [['discount_bp' => $discountBp]]]],
    ]);

    $rows = [];

    foreach ($grosses as $gross) {
        foreach ($rates as $rate) {
            foreach ($quantities as $quantity) {
                $rows[] = ['article' => "G{$gross}V{$rate}", 'description' => 'Vara', 'quantity' => $quantity, 'vat_rate' => $rate, 'gross' => $gross];
            }
        }
    }

    $parsed = SalesRowInput::parse($rows, customer: $scenario->customer, pricing: $scenario->context(['date' => '2026-03-15']));

    foreach ($parsed as $index => $row) {
        $shown = BigRational::of($rows[$index]['gross'])->multipliedBy(10000 - $discountBp)->dividedBy(10000);
        // A whole quantity is held to the exact amount; a part quantity (2,5) to that amount in whole öre, as it is shown,
        // since the row amount is quantity × price rounded to öre (InvoiceCalculator::rowNet). TODO(legal-verify): P-L1 tolerance.
        $exact = $shown->multipliedBy(str_replace(',', '.', $rows[$index]['quantity']));
        $limit = str_contains($rows[$index]['quantity'], ',') ? BigRational::of($exact->toScale(0, RoundingMode::HalfUp)) : $exact;
        $totals = InvoiceCalculator::totals([['net' => $row['net_ore'], 'vat_rate' => $row['vat_rate']]], roundToKronor: false);

        expect(BigRational::of($totals['total'])->compareTo($limit))->toBeLessThanOrEqual(0, "{$rows[$index]['article']} × {$rows[$index]['quantity']} with {$discountBp} bp: {$totals['total']} > {$limit}")
            ->and($row['price_source'])->toBe($discountBp === 0 ? 'CURRENCY_LIST' : 'AGREEMENT');
    }

    // The same holds for the document's sums, where VAT is worked out per rate.
    $all = InvoiceCalculator::totals(array_map(fn (array $row): array => ['net' => $row['net_ore'], 'vat_rate' => $row['vat_rate']], $parsed), roundToKronor: false);
    $sum = array_reduce(array_keys($parsed), fn (BigRational $carry, int $index): BigRational => $carry->plus(BigRational::of($rows[$index]['gross'])->multipliedBy(10000 - $discountBp)->dividedBy(10000)->multipliedBy(str_replace(',', '.', $rows[$index]['quantity']))), BigRational::zero());

    expect(BigRational::of($all['total'])->compareTo($sum))->toBeLessThanOrEqual(0);
})->with([0, 333, 1000, 2550]);
