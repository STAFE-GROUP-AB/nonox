<?php

declare(strict_types=1);

use App\Modules\Pos\PosModule;
use App\Modules\Pos\Support\DailySettlement;
use App\Modules\Pos\Support\Decimals;

/*
 * The daily till voucher's rows (ADR-042): sales and VAT per rate, payments
 * per method, tips, fees and öresutjämning; nothing when it does not add up.
 */

it('books a day with two VAT rates, card, cash and Swish, a refund, a fee and rounding', function () {
    $built = DailySettlement::build(
        [2500 => ['net' => 80000, 'vat' => 20000], 1200 => ['net' => 10000, 'vat' => 1200]],
        ['CARD' => 70000, 'CASH' => 30150, 'SWISH' => 11000],
        0,
        1750,
        4,
        PosModule::DEFAULTS,
    );

    $byAccount = collect($built['rows'])->groupBy('account')->map(fn ($rows) => [$rows->sum('debit_ore'), $rows->sum('credit_ore')])->all();

    expect($built['balanced'])->toBeTrue()
        ->and($built['errors'])->toBe([])
        ->and($byAccount[3001])->toBe([0, 80000])
        ->and($byAccount[2611])->toBe([0, 20000])
        ->and($byAccount[3002])->toBe([0, 10000])
        ->and($byAccount[2621])->toBe([0, 1200])
        ->and($byAccount[1910])->toBe([30150, 0])
        ->and($byAccount[1930])->toBe([11000, 0])
        ->and($byAccount[6570])->toBe([1750, 0])
        // Card 70 000 debit and the fee 1 750 credit on the same settlement account.
        ->and($byAccount[1680])->toBe([70000, 1750])
        // 111 150 paid, 111 200 sold: 50 öre of rounding.
        ->and($byAccount[3740])->toBe([50, 0])
        ->and(array_sum(array_column($built['rows'], 'debit_ore')))->toBe(array_sum(array_column($built['rows'], 'credit_ore')));
});

it('books a day of only refunds on the other side', function () {
    $built = DailySettlement::build([2500 => ['net' => -8000, 'vat' => -2000]], ['CARD' => -10000], 0, 0, 1, PosModule::DEFAULTS);
    $byAccount = collect($built['rows'])->keyBy('account');

    expect($built['balanced'])->toBeTrue()
        ->and($byAccount[3001]['debit_ore'])->toBe(8000)
        ->and($byAccount[2611]['debit_ore'])->toBe(2000)
        ->and($byAccount[1680]['credit_ore'])->toBe(10000);
});

it('credits tips included in card payments', function () {
    $built = DailySettlement::build([1200 => ['net' => 10000, 'vat' => 1200]], ['CARD' => 12200], 1000, 0, 1, PosModule::DEFAULTS);

    expect($built['balanced'])->toBeTrue()
        ->and(collect($built['rows'])->firstWhere('account', 2890)['credit_ore'])->toBe(1000);
});

it('proposes nothing when payments and sales differ by more than rounding', function () {
    $built = DailySettlement::build([2500 => ['net' => 80000, 'vat' => 20000]], ['CARD' => 90000], 0, 0, 2, PosModule::DEFAULTS);

    expect($built['balanced'])->toBeFalse()
        ->and($built['rows'])->toBe([])
        ->and($built['difference'])->toBe(-10000)
        ->and($built['errors'][0])->toContain('100,00');
});

it('converts till numbers exactly', function () {
    expect(Decimals::milli('1.5'))->toBe(1500)
        ->and(Decimals::milli('-2'))->toBe(-2000)
        ->and(Decimals::milli(0.1))->toBe(100)
        ->and(Decimals::milli('abc'))->toBeNull()
        ->and(Decimals::ore(149.5))->toBe(14950)
        ->and(Decimals::ore(8.155))->toBe(816)
        ->and(Decimals::minorUnits('12500'))->toBe(12500)
        ->and(Decimals::minorUnits('12.5'))->toBeNull()
        ->and(Decimals::vatBasisPoints('12'))->toBe(1200)
        ->and(Decimals::vatBasisPoints(25.0))->toBe(2500)
        ->and(Decimals::units(16688))->toBe('166.88');
});
