<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Support;

use App\Support\Money\Money;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Reading amounts, percentages, hours and quantities as people type them on
 * the work order screens: "1 234,50", "15", "1,5".
 */
final class Input
{
    /** Kronor as typed to öre; null when empty. */
    public static function money(mixed $value, string $field, bool $allowNegative = false): ?int
    {
        $typed = trim((string) $value);

        if ($typed === '') {
            return null;
        }

        try {
            $money = Money::parse($typed);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('Beloppet går inte att läsa. Skriv till exempel 1 250,00.')]);
        }

        if (! $allowNegative && $money->isNegative()) {
            throw ValidationException::withMessages([$field => __('Beloppet kan inte vara negativt.')]);
        }

        return $money->ore;
    }

    /** A decimal number as typed ("1,5") in thousandths; null when empty. */
    public static function milli(mixed $value, string $field, string $message): ?int
    {
        $typed = str_replace([' ', "\u{a0}", ','], ['', '', '.'], trim((string) $value));

        if ($typed === '') {
            return null;
        }

        if (preg_match('/^-?\d{1,9}(\.\d{1,3})?$/', $typed) !== 1) {
            throw ValidationException::withMessages([$field => $message]);
        }

        [$whole, $fraction] = array_pad(explode('.', ltrim($typed, '-')), 2, '');
        $milli = (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');

        return str_starts_with($typed, '-') ? -$milli : $milli;
    }

    /** A percentage as typed ("15" or "12,5") in basis points; null when empty. */
    public static function percentBp(mixed $value, string $field): ?int
    {
        $milli = self::milli($value, $field, __('Skriv procent som ett tal, till exempel 15.'));

        if ($milli === null) {
            return null;
        }

        if ($milli < 0 || $milli > 1_000_000) {
            throw ValidationException::withMessages([$field => __('Procenten ska vara mellan 0 och 1 000.')]);
        }

        return intdiv($milli, 10);
    }

    /** Hours as typed ("1,5" or "1:30") in minutes; null when empty. */
    public static function minutes(mixed $value, string $field): ?int
    {
        $typed = trim((string) $value);

        if ($typed === '') {
            return null;
        }

        if (preg_match('/^(\d{1,3}):([0-5]\d)$/', $typed, $match) === 1) {
            return (int) $match[1] * 60 + (int) $match[2];
        }

        $milli = self::milli($typed, $field, __('Skriv tiden i timmar, till exempel 1,5 eller 1:30.'));

        return $milli === null ? null : (int) round($milli * 60 / 1000);
    }
}
