<?php

declare(strict_types=1);

namespace App\Support\Landing;

/**
 * The comparison with other bookkeeping programs on the public pages
 * (ADR-020): features and the yearly price for a company with five
 * employees, read from docs/migration/jamforelse.json, where every price
 * has its source and the time it was fetched.
 */
final class Comparison
{
    /**
     * @return array{
     *     fetched_at: string,
     *     timezone: string,
     *     scenario: string,
     *     programs: list<array{slug: string, name: string, plan: string, per_year: int, per_month?: int, price_note: string, source: ?string, verified: bool}>,
     *     legend: array<string, string>,
     *     features: list<array{name: string, values: array<string, string>}>
     * }
     */
    public static function data(): array
    {
        /** @var array{fetched_at: string, timezone: string, scenario: string, programs: list<array{slug: string, name: string, plan: string, per_year: int, per_month?: int, price_note: string, source: ?string, verified: bool}>, legend: array<string, string>, features: list<array{name: string, values: array<string, string>}>} $data */
        $data = json_decode((string) file_get_contents(base_path('docs/migration/jamforelse.json')), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * Another program by its slug, or null for NONOX itself or an unknown slug.
     *
     * @return array{slug: string, name: string, plan: string, per_year: int, per_month?: int, price_note: string, source: ?string, verified: bool}|null
     */
    public static function program(string $slug): ?array
    {
        foreach (self::data()['programs'] as $program) {
            if ($program['slug'] === $slug && $slug !== 'nonox') {
                return $program;
            }
        }

        return null;
    }

    /** @return list<string> the slugs of the other programs */
    public static function others(): array
    {
        return array_values(array_filter(array_column(self::data()['programs'], 'slug'), fn (string $slug): bool => $slug !== 'nonox'));
    }
}
