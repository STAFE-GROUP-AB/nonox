<?php

declare(strict_types=1);

use App\Support\Currency\ExchangeRate;

it('reads a rate as people type it and refuses what is not one', function () {
    expect(ExchangeRate::parse('USD', '9,8264')?->micros)->toBe(9_826_400)
        ->and(ExchangeRate::parse('USD', '10')?->micros)->toBe(10_000_000)
        ->and(ExchangeRate::parse('EUR', ' 11.234567 ')?->micros)->toBe(11_234_567)
        ->and(ExchangeRate::parse('USD', ''))->toBeNull()
        ->and(ExchangeRate::parse('USD', '0'))->toBeNull()
        ->and(ExchangeRate::parse('USD', '-9,8'))->toBeNull()
        ->and(ExchangeRate::parse('USD', '9,1234567'))->toBeNull();
});

it('converts hundredths of a currency to öre without floats, rounding half up', function () {
    $rate = new ExchangeRate('USD', '2026-10-30', 9_826_350, 'riksbank');

    expect($rate->toOre(151_750))->toBe(1_491_149)
        ->and($rate->toOre(-151_750))->toBe(-1_491_149)
        ->and((new ExchangeRate('EUR', '', 1_500_000, 'manual'))->toOre(1))->toBe(2)
        ->and($rate->format())->toBe('9,826350');
});
