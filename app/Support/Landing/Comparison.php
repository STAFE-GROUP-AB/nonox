<?php

declare(strict_types=1);

namespace App\Support\Landing;

/**
 * The comparison with other bookkeeping programs on the public pages
 * (ADR-020): features and the yearly price for a company with five
 * employees, read from docs/migration/jamforelse.json, where every price
 * has its source and the time it was fetched.
 *
 * Each program's price_model splits that list price into a monthly base and
 * a fee per employee, from the same source and the same fetch, so the
 * industry pages (docs/branscher) can show the price for their own number
 * of employees without a single figure that is not in the data file.
 *
 * @phpstan-type PriceModel array{base_per_month: int, per_employee_per_month: ?int, included_employees: int, comparable?: bool}
 * @phpstan-type Program array{slug: string, name: string, plan: string, per_year: int, per_month?: int, price_note: string, source: ?string, verified: bool, price_model?: PriceModel}
 * @phpstan-type Quote array{slug: string, name: string, plan: string, per_year: ?int, formula: string, price_note: string, source: ?string, verified: bool, comparable: bool}
 */
final class Comparison
{
    /**
     * @return array{
     *     fetched_at: string,
     *     timezone: string,
     *     scenario_employees: int,
     *     scenario: string,
     *     programs: list<Program>,
     *     price_model_note: string,
     *     legend: array<string, string>,
     *     features: list<array{name: string, values: array<string, string>}>
     * }
     */
    public static function data(): array
    {
        /** @var array{fetched_at: string, timezone: string, scenario_employees: int, scenario: string, programs: list<Program>, price_model_note: string, legend: array<string, string>, features: list<array{name: string, values: array<string, string>}>} $data */
        $data = json_decode((string) file_get_contents(base_path('docs/migration/jamforelse.json')), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * Another program by its slug, or null for NONOX itself or an unknown slug.
     *
     * @return Program|null
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

    /**
     * A program's list price per year for a company with this many
     * employees, from its price model; null when the source only gives a
     * fixed price and the number of employees is not the scenario's.
     *
     * @param  Program  $program
     */
    public static function perYearFor(array $program, int $employees): ?int
    {
        $model = $program['price_model'] ?? null;

        if ($model === null) {
            return $employees === self::data()['scenario_employees'] ? $program['per_year'] : null;
        }

        if ($model['per_employee_per_month'] === null) {
            return $employees === self::data()['scenario_employees'] ? 12 * $model['base_per_month'] : null;
        }

        return 12 * ($model['base_per_month'] + self::paidEmployees($model, $employees) * $model['per_employee_per_month']);
    }

    /**
     * The other programs' prices for this many employees, with the sum
     * written out so a reader can check it against the source.
     *
     * @return list<Quote>
     */
    public static function quotesFor(int $employees): array
    {
        $quotes = [];

        foreach (self::data()['programs'] as $program) {
            if ($program['slug'] === 'nonox') {
                continue;
            }

            $quotes[] = [
                'slug' => $program['slug'],
                'name' => $program['name'],
                'plan' => $program['plan'],
                'per_year' => self::perYearFor($program, $employees),
                'formula' => self::formula($program, $employees),
                'price_note' => $program['price_note'],
                'source' => $program['source'],
                'verified' => $program['verified'],
                'comparable' => $program['price_model']['comparable'] ?? true,
            ];
        }

        return $quotes;
    }

    /** @param  Program  $program */
    private static function formula(array $program, int $employees): string
    {
        $model = $program['price_model'] ?? null;

        if ($model === null || $model['per_employee_per_month'] === null) {
            return $employees === self::data()['scenario_employees']
                ? 'Fast pris för paketet enligt prissidan.'
                : 'Prissidan anger bara ett fast pris för paketet, inte vad det kostar med '.self::employeesText($employees).'.';
        }

        $paid = self::paidEmployees($model, $employees);
        $sum = $paid > 0
            ? "{$model['base_per_month']} + {$paid} × {$model['per_employee_per_month']} kr/mån"
            : "{$model['base_per_month']} kr/mån";

        return "12 × ({$sum})";
    }

    /** @param  PriceModel  $model */
    private static function paidEmployees(array $model, int $employees): int
    {
        return max(0, $employees - $model['included_employees']);
    }

    public static function employeesText(int $employees): string
    {
        $words = [1 => 'en', 2 => 'två', 3 => 'tre', 4 => 'fyra', 5 => 'fem', 6 => 'sex', 7 => 'sju', 8 => 'åtta', 9 => 'nio', 10 => 'tio'];
        $number = $words[$employees] ?? (string) $employees;

        return $employees === 1 ? "{$number} anställd" : "{$number} anställda";
    }
}
