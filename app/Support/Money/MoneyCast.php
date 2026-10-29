<?php

declare(strict_types=1);

namespace App\Support\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a bigint `*_ore` column to Money (AGENTS.md §6.3). The currency comes
 * from the model's `currency` attribute when it has one, otherwise SEK.
 *
 * @implements CastsAttributes<Money, mixed>
 */
final class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::ofOre((int) $value, self::currencyOf($attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Money) {
            return $value->ore;
        }

        if (is_int($value)) {
            return $value;
        }

        throw new InvalidArgumentException("{$key} must be Money or an integer number of öre, never a float or string.");
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function currencyOf(array $attributes): string
    {
        $currency = $attributes['currency'] ?? null;

        return is_string($currency) && $currency !== '' ? $currency : 'SEK';
    }
}
