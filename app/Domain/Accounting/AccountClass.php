<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * The BAS account classes, used to group the chart of accounts.
 */
final class AccountClass
{
    /** @return array<int, string> */
    public static function all(): array
    {
        return [
            1 => __('Tillgångar'),
            2 => __('Eget kapital och skulder'),
            3 => __('Rörelsens intäkter'),
            4 => __('Varor, material och tjänster'),
            5 => __('Lokaler, resor och marknadsföring'),
            6 => __('Kontor, tjänster och övriga kostnader'),
            7 => __('Personal, avskrivningar m.m.'),
            8 => __('Finansiella poster, bokslut och skatt'),
            9 => __('Interna konton'),
        ];
    }

    public static function of(int $number): int
    {
        return intdiv($number, 1000);
    }
}
