<?php

declare(strict_types=1);

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;

const NBSP = "\u{00A0}";

it('reads amounts from other systems exactly, never through a float', function (mixed $input, ?int $ore) {
    expect(Money::fromDecimal($input)?->ore)->toBe($ore);
})->with([
    'string 8.155' => ['8.155', 816],
    'float 8.155' => [8.155, 816],
    'string 1.005' => ['1.005', 101],
    'float 1.005' => [1.005, 101],
    'negative string' => ['-8.155', -816],
    'negative float' => [-1.005, -101],
    'unicode minus' => ["\u{2212}12,50", -1250],
    'comma decimal' => ['12,5', 1250],
    'space thousands' => ['1 234 567,891', 123456789],
    'nbsp thousands' => ['1'.NBSP.'234,50', 123450],
    'dot thousands, comma decimal' => ['1.234,56', 123456],
    'comma thousands, dot decimal' => ['1,234.56', 123456],
    'repeated dot thousands' => ['1.234.567', 123456700],
    'kronor suffix' => ['99,90 kr', 9990],
    'integer' => [42, 4200],
    'float integer' => [42.0, 4200],
    'many decimals' => ['0.004999', 0],
    'tiny float' => [1.0E-5, 0],
    'zero' => ['0', 0],
    'text' => ['abc', null],
    'empty' => ['', null],
    'null' => [null, null],
    'two decimal commas' => ['1,234,56.7,8', null],
    'trailing separator' => ['12,', null],
    'not finite' => [INF, null],
    'array' => [[1], null],
]);

it('rounds öre half up away from zero, or as asked', function () {
    expect(Money::fromDecimal('0.125')?->ore)->toBe(13)
        ->and(Money::fromDecimal('-0.125')?->ore)->toBe(-13)
        ->and(Money::fromDecimal('0.125', rounding: RoundingMode::HalfEven)?->ore)->toBe(12)
        ->and(round((float) '8.155' * 100))->toBe(815.0);
});

it('parses amounts the way people type them in Sweden', function (string $input, int $ore) {
    expect(Money::parse($input)->ore)->toBe($ore);
})->with([
    'comma decimals' => ['1234,50', 123450],
    'space thousands' => ['1 234,50', 123450],
    'non-breaking space' => ['1'.NBSP.'234,50', 123450],
    'dot decimals' => ['1234.50', 123450],
    'one decimal' => ['12,5', 1250],
    'whole kronor' => ['1234', 123400],
    'with kr' => ['1 234,50 kr', 123450],
    'negative' => ['-1 234,50', -123450],
    'unicode minus' => ["\u{2212}99,90", -9990],
    'zero' => ['0', 0],
    'large amount' => ['12 345 678,99', 1234567899],
]);

it('refuses input it cannot read exactly', function (string $input) {
    Money::parse($input);
})->with([
    'three decimals' => ['1,234'.'5'],
    'letters' => ['tolv kronor'],
    'empty' => [''],
    'thousands with dots' => ['1.234,50'],
    'two signs' => ['--5'],
])->throws(InvalidArgumentException::class);

it('formats amounts in Swedish with non-breaking spaces', function (int $ore, string $expected) {
    expect(Money::ofOre($ore)->format())->toBe(str_replace(' ', NBSP, $expected));
})->with([
    [123450, '1 234,50 kr'],
    [5, '0,05 kr'],
    [0, '0,00 kr'],
    [-123450, '-1 234,50 kr'],
    [1234567899, '12 345 678,99 kr'],
]);

it('formats other currencies with their code', function () {
    expect(Money::ofOre(1000, 'EUR')->format())->toBe('10,00'.NBSP.'EUR')
        ->and(Money::ofOre(1000)->format(withCurrency: false))->toBe('10,00');
});

it('adds and subtracts without floats', function () {
    $a = Money::parse('0,10');
    $b = Money::parse('0,20');

    expect($a->plus($b)->ore)->toBe(30)
        ->and($a->minus($b)->ore)->toBe(-10)
        ->and($a->plus($b)->equals(Money::parse('0,30')))->toBeTrue();
});

it('calculates VAT from basis points with half-up rounding', function (int $ore, int $rate, int $vat) {
    expect(Money::ofOre($ore)->percent($rate)->ore)->toBe($vat);
})->with([
    '25 %' => [10000, 2500, 2500],
    '12 %' => [10000, 1200, 1200],
    '6 %' => [10000, 600, 600],
    'zero rated' => [10000, 0, 0],
    'rounds half up' => [2, 2500, 1],
    'rounds down below half' => [1, 2500, 0],
    'credit note mirrors invoice' => [-2, 2500, -1],
]);

it('multiplies by decimal quantities given as strings', function () {
    expect(Money::parse('99,90')->times('1.5')->ore)->toBe(14985)
        ->and(Money::parse('0,05')->times('0.5')->ore)->toBe(3)
        ->and(Money::parse('0,05')->times('0.5', RoundingMode::Down)->ore)->toBe(2)
        ->and(Money::parse('10,00')->times(-3)->ore)->toBe(-3000);
});

it('rounds to whole kronor for öresavrundning', function (string $amount, string $rounded) {
    expect(Money::parse($amount)->roundedToKronor()->equals(Money::parse($rounded)))->toBeTrue();
})->with([
    ['1 234,49', '1234'],
    ['1 234,50', '1235'],
    ['-1 234,50', '-1235'],
    ['0,49', '0'],
]);

it('is immutable', function () {
    $money = Money::parse('100');

    $money->plus(Money::parse('1'));
    $money->negated();

    expect($money->ore)->toBe(10000);
});

it('refuses to mix currencies', function () {
    Money::ofOre(100)->plus(Money::ofOre(100, 'EUR'));
})->throws(InvalidArgumentException::class);

it('refuses invalid currency codes', function () {
    Money::ofOre(100, 'kr');
})->throws(InvalidArgumentException::class);

it('casts öre columns to Money and back, never accepting floats', function () {
    $cast = new MoneyCast;
    $model = new class extends Model {};

    expect($cast->get($model, 'amount_ore', 12345, ['currency' => 'EUR'])?->format())->toBe('123,45'.NBSP.'EUR')
        ->and($cast->get($model, 'amount_ore', '12345', [])?->currency)->toBe('SEK')
        ->and($cast->get($model, 'amount_ore', null, []))->toBeNull()
        ->and($cast->set($model, 'amount_ore', Money::parse('12,34'), []))->toBe(1234)
        ->and($cast->set($model, 'amount_ore', 500, []))->toBe(500)
        ->and(fn () => $cast->set($model, 'amount_ore', 12.34, []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $cast->set($model, 'amount_ore', '12,34', []))->toThrow(InvalidArgumentException::class);
});
