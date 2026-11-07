<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use Carbon\CarbonInterface;
use LogicException;

/**
 * The rules behind the K3 annual report (ADR-059, addendum 2026-11-07,
 * A.2.2): the thresholds for a större företag (ÅRL 1 kap. 3 §, BFNAR
 * 2006:11), the K2 1.1C exception and the K3 version per fiscal year. Data,
 * not code: database/data/annual-report/k3-rules.json, every row with its
 * rule id, source URL, quote, status and the date it was read. Nothing there
 * is legally verified.
 *
 * A row applies to a fiscal year that begins on or after `starts_from` and
 * before `starts_before` (null = no limit). A row with `extended_first_year`
 * also applies to the company's first, extended fiscal year that begins
 * after `starts_after` and ends on or after `ends_on_or_after` (BFNAR 2025:2
 * and 2025:3 point 1).
 *
 * @phpstan-type K3RuleEntry array{value: mixed, starts_from: ?string, starts_before: ?string, extended_first_year?: array{starts_after: string, ends_on_or_after: string}, rule_id: string, status: string, read: string, source_ref: string, source_url: string, source_quote: string, note?: string}
 */
final class K3Rules
{
    /** @var ?array<string, list<K3RuleEntry>> */
    private static ?array $rules = null;

    /**
     * The row in force for a fiscal year that begins on $start, or null when
     * the data has none. Without a start, the row that is in force today
     * without an end (starts_before null).
     *
     * @return ?K3RuleEntry
     */
    public static function entry(string $key, ?CarbonInterface $start = null, ?CarbonInterface $end = null, bool $firstYear = false): ?array
    {
        $rows = self::entries()[$key] ?? throw new LogicException("Missing K3 rule {$key}.");

        if ($start === null) {
            foreach ($rows as $row) {
                if ($row['starts_before'] === null) {
                    return $row;
                }
            }

            return null;
        }

        $date = $start->toDateString();

        foreach ($rows as $row) {
            if (self::extendedFirstYear($row, $date, $end, $firstYear)) {
                return $row;
            }
        }

        foreach ($rows as $row) {
            if (($row['starts_from'] === null || $date >= $row['starts_from']) && ($row['starts_before'] === null || $date < $row['starts_before'])) {
                return $row;
            }
        }

        return null;
    }

    /** @return K3RuleEntry */
    public static function rule(string $key, ?CarbonInterface $start = null, ?CarbonInterface $end = null, bool $firstYear = false): array
    {
        return self::entry($key, $start, $end, $firstYear)
            ?? throw new LogicException("K3 rule {$key} has no value for a fiscal year that begins {$start?->toDateString()}.");
    }

    public static function int(string $key, ?CarbonInterface $start = null, ?CarbonInterface $end = null, bool $firstYear = false): int
    {
        $value = self::rule($key, $start, $end, $firstYear)['value'];

        return is_int($value) ? $value : throw new LogicException("K3 rule {$key} is not a number.");
    }

    /** @return array<string, list<K3RuleEntry>> */
    public static function entries(): array
    {
        if (self::$rules === null) {
            /** @var array{rules: array<string, list<K3RuleEntry>>} $data */
            $data = json_decode((string) file_get_contents(database_path('data/annual-report/k3-rules.json')), true, flags: JSON_THROW_ON_ERROR);
            self::$rules = $data['rules'];
        }

        return self::$rules;
    }

    /** @param K3RuleEntry $row */
    private static function extendedFirstYear(array $row, string $start, ?CarbonInterface $end, bool $firstYear): bool
    {
        $extended = $row['extended_first_year'] ?? null;

        if ($extended === null || ! $firstYear || $end === null) {
            return false;
        }

        return $start > $extended['starts_after'] && $end->toDateString() >= $extended['ends_on_or_after'];
    }
}
