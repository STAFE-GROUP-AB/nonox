<?php

declare(strict_types=1);

namespace App\Domain\Signing\Chain;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The one canonical JSON of the signing chain (ADR-046 amendment, gap 4):
 * object keys sorted at every level, lists kept in order, UTF-8 and slashes
 * unescaped, no floats (a float has no single text form), and every time in
 * UTC with microseconds ("2026-06-15T08:00:00.000000Z"; the event times are
 * whole seconds, as their column keeps them). The same input
 * always gives the same bytes, so anyone can recompute a hash from the rows.
 */
final class CanonicalJson
{
    /** @param  array<mixed>  $value */
    public static function encode(array $value): string
    {
        return json_encode(self::normalise($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** A time as the chain writes it: UTC with microseconds, or null. */
    public static function time(DateTimeInterface|string|null $time): ?string
    {
        return $time === null ? null : self::parse($time)->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * A time to whole seconds in UTC, for columns stored without fractions
     * (the signing request's own times).
     */
    public static function seconds(DateTimeInterface|string|null $time): ?string
    {
        return $time === null ? null : self::parse($time)->format('Y-m-d\TH:i:s\Z');
    }

    private static function parse(DateTimeInterface|string $time): CarbonImmutable
    {
        return ($time instanceof DateTimeInterface ? CarbonImmutable::instance($time) : CarbonImmutable::parse($time))->utc();
    }

    private static function normalise(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('The signing chain holds no floats (ADR-046 S2).');
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::normalise(...), $value);
    }
}
