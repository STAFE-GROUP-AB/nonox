<?php

declare(strict_types=1);

use App\Domain\Company\GiroNumber;
use App\Support\Luhn;

function withCheckDigit(string $body): string
{
    return $body.Luhn::checkDigit($body);
}

it('formats valid bankgiro numbers', function () {
    $seven = withCheckDigit('123456');
    $eight = withCheckDigit('5050105');

    expect(GiroNumber::bankgiro($seven))->toBe(substr($seven, 0, 3).'-'.substr($seven, 3))
        ->and(GiroNumber::bankgiro($eight))->toBe(substr($eight, 0, 4).'-'.substr($eight, 4))
        ->and(GiroNumber::bankgiro(substr($seven, 0, 3).'-'.substr($seven, 3)))->not->toBeNull();
});

it('rejects invalid bankgiro numbers', function (string $input) {
    expect(GiroNumber::bankgiro($input))->toBeNull();
})->with([
    'wrong check digit' => [substr(withCheckDigit('123456'), 0, 6).((Luhn::checkDigit('123456') + 1) % 10)],
    'too short' => ['12345'],
    'too long' => ['123456789'],
    'letters' => ['12A-4567'],
]);

it('formats valid plusgiro numbers with the check digit last', function () {
    $number = withCheckDigit('123456');

    expect(GiroNumber::plusgiro('12 34 56-'.substr($number, -1)))->toBe('123456-'.substr($number, -1));
});

it('rejects invalid plusgiro numbers', function () {
    $wrong = '123456'.((Luhn::checkDigit('123456') + 1) % 10);

    expect(GiroNumber::plusgiro($wrong))->toBeNull()
        ->and(GiroNumber::plusgiro('1'))->toBeNull();
});
