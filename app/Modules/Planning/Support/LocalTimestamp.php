<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A timestamptz column read as a CarbonImmutable in Europe/Stockholm and
 * written with its UTC offset, so an instant is stored right whatever the
 * database session's time zone, also in the hour that repeats when summer
 * time ends.
 *
 * @implements CastsAttributes<CarbonImmutable, DateTimeInterface|string>
 */
final class LocalTimestamp implements CastsAttributes
{
    public const string ZONE = 'Europe/Stockholm';

    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->setTimezone(self::ZONE);
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $instant = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value, self::ZONE);

        return $instant->utc()->format('Y-m-d H:i:sP');
    }
}
