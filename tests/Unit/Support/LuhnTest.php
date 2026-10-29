<?php

declare(strict_types=1);

use App\Domain\Company\OrganisationNumber;
use App\Support\Luhn;

it('computes the check digit', function (string $body, int $expected) {
    expect(Luhn::checkDigit($body))->toBe($expected);
})->with([
    'textbook example' => ['7992739871', 3],
    'all zeros' => ['000000000', 0],
    'single digit' => ['5', 9],
]);

it('accepts numbers with a correct check digit', function (string $digits) {
    expect(Luhn::isValid($digits))->toBeTrue();
})->with([
    '79927398713',
    '0000000000',
    '59',
]);

it('rejects numbers with a wrong check digit', function (string $digits) {
    expect(Luhn::isValid($digits))->toBeFalse();
})->with([
    '79927398710',
    '79927398719',
    '58',
]);

it('rejects input that is not digits', function (string $input) {
    expect(Luhn::isValid($input))->toBeFalse();
})->with([
    'empty' => [''],
    'single digit' => ['7'],
    'letters' => ['55667A8899'],
    'hyphen' => ['556677-8899'],
]);

it('refuses to compute a check digit for non-digits', function () {
    Luhn::checkDigit('12a4');
})->throws(InvalidArgumentException::class);

it('normalises organisation numbers to the ten-digit form', function (string $input) {
    $body = '556123456';
    $valid = $body.Luhn::checkDigit($body);

    $number = OrganisationNumber::tryFrom(str_replace('VALID', $valid, $input));

    expect($number?->formatted())->toBe('556123-'.substr($valid, 6));
})->with([
    'plain' => ['VALID'],
    'with century' => ['16VALID'],
    'surrounded by spaces' => [' VALID '],
]);

it('accepts hyphenated organisation numbers', function () {
    $body = '556123456';
    $valid = $body.Luhn::checkDigit($body);
    $hyphenated = substr($valid, 0, 6).'-'.substr($valid, 6);

    expect(OrganisationNumber::tryFrom($hyphenated)?->digits)->toBe($valid);
});

it('rejects invalid organisation numbers', function (string $input) {
    expect(OrganisationNumber::tryFrom($input))->toBeNull();
})->with([
    'wrong check digit' => ['5561234560'],
    'too short' => ['55612345'],
    'too long' => ['55612345678'],
    'letters' => ['55612345AB'],
]);

it('derives the VAT number from the organisation number', function () {
    $body = '556123456';
    $valid = $body.Luhn::checkDigit($body);

    expect(OrganisationNumber::tryFrom($valid)?->vatNumber())->toBe("SE{$valid}01");
});
