<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

/**
 * Reads a provider's decimal amount ("1234.5", "-12.30") into öre without
 * floating point (AGENTS.md §1.2).
 */
final class Amount
{
    public static function toOre(string|int|float $amount): int
    {
        $text = trim(is_string($amount) ? $amount : (string) $amount);

        if (preg_match('/^(-|\+)?(\d+)(?:\.(\d{1,2})0*)?$/', $text, $match) !== 1) {
            throw new ProviderFailed('The provider sent an amount that is not a decimal number.');
        }

        $ore = (int) $match[2] * 100 + (int) str_pad($match[3] ?? '0', 2, '0');

        return $match[1] === '-' ? -$ore : $ore;
    }
}
