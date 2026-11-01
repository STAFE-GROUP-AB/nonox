<?php

declare(strict_types=1);

namespace App\Modules\Connect\Support;

use Illuminate\Validation\ValidationException;

/**
 * Turns API input into what the app's Actions read (ADR-032). The API speaks
 * whole öre as integers and basis points for discounts; the Actions read
 * amounts as written in a form ("1234.50"). Converting here keeps a single
 * set of Actions for the UI and the API, with exact arithmetic: no float
 * ever touches an amount.
 */
final class ApiInput
{
    /**
     * An integer öre amount as kronor text, or a validation error on $field.
     *
     * @throws ValidationException
     */
    public static function kronor(mixed $ore, string $field): string
    {
        if (! is_int($ore) || abs($ore) > 1_000_000_000_000) {
            throw ValidationException::withMessages([$field => __('Skriv beloppet som ett heltal i öre, till exempel 125000 för 1 250,00 kr.')]);
        }

        $sign = $ore < 0 ? '-' : '';
        $abs = abs($ore);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /**
     * Basis points (1050 is 10.5 %) as the percent text the Actions read.
     *
     * @throws ValidationException
     */
    public static function percent(mixed $basisPoints, string $field): string
    {
        if (! is_int($basisPoints) || $basisPoints < 0 || $basisPoints > 10000) {
            throw ValidationException::withMessages([$field => __('Skriv rabatten i hundradels procent som ett heltal mellan 0 och 10000, till exempel 1050 för 10,5 %.')]);
        }

        return sprintf('%d.%02d', intdiv($basisPoints, 100), $basisPoints % 100);
    }

    /**
     * A quantity as text: an integer, or a decimal string with up to three
     * decimals ("1.5"). JSON numbers with decimals are refused, since a float
     * cannot be trusted to be exact.
     *
     * @throws ValidationException
     */
    public static function quantity(mixed $value, string $field): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value) && preg_match('/^-?\d{1,9}(\.\d{1,3})?$/', $value) === 1) {
            return $value;
        }

        throw ValidationException::withMessages([$field => __('Skriv antalet som ett heltal eller som text med högst tre decimaler, till exempel "1.5".')]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function body(mixed $body): array
    {
        return is_array($body) ? $body : [];
    }
}
