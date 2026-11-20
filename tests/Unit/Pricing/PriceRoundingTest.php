<?php

declare(strict_types=1);

use App\Domain\Pricing\PriceRounding;
use Brick\Math\Exception\IntegerOverflowException;

/*
 * ADR-082 §7: every rounding rule on integer minor units. The step rules
 * round to the nearest step with HalfUp (a half goes away from zero); the
 * "ends in" rules take the nearest ending at or above. Negative amounts are
 * rounded as their size, so every rule is symmetric around zero, and zero
 * stays zero (a free article stays free).
 */

it('rounds with each rule', function (PriceRounding $rule, int $minor, int $expected) {
    expect($rule->apply($minor))->toBe($expected);
})->with([
    'none keeps öre' => [PriceRounding::None, 12_345, 12_345],
    'none keeps 1 öre' => [PriceRounding::None, 1, 1],

    'whole: down below a half' => [PriceRounding::Whole, 12_349, 12_300],
    'whole: a half goes up' => [PriceRounding::Whole, 12_350, 12_400],
    'whole: up above a half' => [PriceRounding::Whole, 12_351, 12_400],
    'whole: already whole' => [PriceRounding::Whole, 12_300, 12_300],
    'whole: 1 öre never rounds to zero' => [PriceRounding::Whole, 1, 100],
    'whole: 49 öre never rounds to zero' => [PriceRounding::Whole, 49, 100],
    'whole: 50 öre' => [PriceRounding::Whole, 50, 100],

    'half: down below a quarter' => [PriceRounding::Half, 12_324, 12_300],
    'half: a quarter goes up' => [PriceRounding::Half, 12_325, 12_350],
    'half: up to the next krona' => [PriceRounding::Half, 12_375, 12_400],
    'half: 74 öre' => [PriceRounding::Half, 12_374, 12_350],
    'half: 1 öre never rounds to zero' => [PriceRounding::Half, 1, 50],
    'half: 24 öre never rounds to zero' => [PriceRounding::Half, 24, 50],

    'tens: down below 5 kr' => [PriceRounding::Tens, 144_499, 144_000],
    'tens: 5 kr goes up' => [PriceRounding::Tens, 144_500, 145_000],
    'tens: 1 öre never rounds to zero' => [PriceRounding::Tens, 1, 1_000],
    'tens: 4,99 kr never rounds to zero' => [PriceRounding::Tens, 499, 1_000],
    'tens: 5,00 kr' => [PriceRounding::Tens, 500, 1_000],

    'ten öre 9: already ,90' => [PriceRounding::TenOre9, 12_390, 12_390],
    'ten öre 9: just above ,90 goes to the next krona' => [PriceRounding::TenOre9, 12_391, 12_490],
    'ten öre 9: just below ,90' => [PriceRounding::TenOre9, 12_389, 12_390],
    'ten öre 9: a whole krona' => [PriceRounding::TenOre9, 12_300, 12_390],
    'ten öre 9: 1 öre' => [PriceRounding::TenOre9, 1, 90],

    'ends in 9: already 149' => [PriceRounding::EndsIn9, 14_900, 14_900],
    'ends in 9: 1 öre above 149' => [PriceRounding::EndsIn9, 14_901, 15_900],
    'ends in 9: 141 kr' => [PriceRounding::EndsIn9, 14_100, 14_900],
    'ends in 9: 99 kr' => [PriceRounding::EndsIn9, 9_900, 9_900],
    'ends in 9: 1 290,50 kr' => [PriceRounding::EndsIn9, 129_050, 129_900],
    'ends in 9: 1 öre' => [PriceRounding::EndsIn9, 1, 900],
    'ends in 9: 9 kr' => [PriceRounding::EndsIn9, 900, 900],
    'ends in 9: 9,01 kr' => [PriceRounding::EndsIn9, 901, 1_900],
]);

it('keeps zero at zero with every rule', function (PriceRounding $rule) {
    expect($rule->apply(0))->toBe(0);
})->with(PriceRounding::cases());

it('rounds a negative amount as its size, so each rule is symmetric', function (PriceRounding $rule, int $minor) {
    expect($rule->apply(-$minor))->toBe(-$rule->apply($minor));
})->with(PriceRounding::cases())->with([1, 49, 50, 12_325, 12_350, 12_391, 14_901, 145_000]);

it('rounds a negative half away from zero', function () {
    expect(PriceRounding::Whole->apply(-12_350))->toBe(-12_400)
        ->and(PriceRounding::Whole->apply(-12_349))->toBe(-12_300)
        ->and(PriceRounding::EndsIn9->apply(-14_901))->toBe(-15_900);
});

it('never moves a price by more than one step', function (PriceRounding $rule, int $step) {
    foreach ([1, 7, 99, 101, 12_345, 987_654_321, 5_000_000_000_000] as $minor) {
        $rounded = $rule->apply($minor);

        expect(abs($rounded - $minor))->toBeLessThanOrEqual($step, "{$rule->value} {$minor}");
    }
})->with([
    [PriceRounding::None, 0],
    [PriceRounding::Whole, 100],
    [PriceRounding::Half, 50],
    [PriceRounding::Tens, 1_000],
    [PriceRounding::TenOre9, 100],
    [PriceRounding::EndsIn9, 1_000],
]);

it('is idempotent: a rounded price stays as it is', function (PriceRounding $rule) {
    foreach ([1, 50, 12_345, 14_901, 987_654_321] as $minor) {
        expect($rule->apply($rule->apply($minor)))->toBe($rule->apply($minor));
    }
})->with(PriceRounding::cases());

it('handles very large amounts exactly, and refuses a result outside the integer range', function () {
    expect(PriceRounding::Whole->apply(PHP_INT_MAX))->toBe(9_223_372_036_854_775_800)
        ->and(PriceRounding::Whole->apply(PHP_INT_MIN + 1))->toBe(-9_223_372_036_854_775_800)
        ->and(PriceRounding::None->apply(PHP_INT_MIN))->toBe(PHP_INT_MIN)
        ->and(PriceRounding::EndsIn9->apply(9_000_000_000_000_000_001))->toBe(9_000_000_000_000_000_900)
        ->and(fn () => PriceRounding::Tens->apply(PHP_INT_MAX))->toThrow(IntegerOverflowException::class)
        ->and(fn () => PriceRounding::EndsIn9->apply(PHP_INT_MAX))->toThrow(IntegerOverflowException::class);
});

it('has a Swedish label for every rule', function (PriceRounding $rule) {
    expect($rule->label())->not->toBe('');
})->with(PriceRounding::cases());

it('never rounds a price above zero to zero, nor one below zero (Lead decision 2026-11-20)', function (PriceRounding $rule) {
    foreach ([1, 24, 49, 499] as $minor) {
        expect($rule->apply($minor))->toBeGreaterThan(0, "{$rule->value} {$minor}")
            ->and($rule->apply(-$minor))->toBeLessThan(0, "{$rule->value} -{$minor}");
    }
})->with([PriceRounding::Whole, PriceRounding::Half, PriceRounding::Tens, PriceRounding::TenOre9, PriceRounding::EndsIn9]);
