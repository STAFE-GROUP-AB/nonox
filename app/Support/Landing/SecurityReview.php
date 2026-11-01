<?php

declare(strict_types=1);

namespace App\Support\Landing;

/**
 * The public account of NONOX's latest security and compliance review, on
 * /sakerhet and the landing page: when it was done, how, by whom, a grade
 * per area with what is done and what is still open. The facts live in
 * docs/sakerhet/granskning.json, versioned with the code they describe, so
 * every change to a grade can be followed in the history.
 */
final class SecurityReview
{
    /** Grades from best to worst. */
    public const array GRADES = ['A', 'B', 'C', 'D', 'E'];

    /**
     * @return array{
     *     reviewed_at: string,
     *     version: string,
     *     kind: string,
     *     method: string,
     *     independent: bool,
     *     independent_note: string,
     *     scale: array<string, string>,
     *     findings: array<string, array{found: int, fixed: int}>,
     *     areas: list<array{group: string, name: string, grade: string, summary: string, done: list<string>, open: list<string>, law: list<string>}>
     * }
     */
    public static function data(): array
    {
        /** @var array{reviewed_at: string, version: string, kind: string, method: string, independent: bool, independent_note: string, scale: array<string, string>, findings: array<string, array{found: int, fixed: int}>, areas: list<array{group: string, name: string, grade: string, summary: string, done: list<string>, open: list<string>, law: list<string>}>} $data */
        $data = json_decode((string) file_get_contents(base_path('docs/sakerhet/granskning.json')), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @return list<array{group: string, name: string, grade: string, summary: string, done: list<string>, open: list<string>, law: list<string>}>
     */
    public static function areas(string $group): array
    {
        return array_values(array_filter(self::data()['areas'], fn (array $area): bool => $area['group'] === $group));
    }

    /** @return array{found: int, fixed: int} all findings, every severity */
    public static function totals(): array
    {
        $findings = self::data()['findings'];

        return [
            'found' => array_sum(array_column($findings, 'found')),
            'fixed' => array_sum(array_column($findings, 'fixed')),
        ];
    }
}
