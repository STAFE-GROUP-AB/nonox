<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Domain;

use Carbon\CarbonImmutable;

/**
 * Compares an employment's pay with the agreement's minimum wage for its
 * lönegrupp, age and experience (ADR-033, gap G9, step A4). Deterministic:
 * age from the date of birth, experience from the date the employment
 * records for branschvana, time in the company from the employment's start.
 * The step in force at the end of the pay period is used; among the group's
 * rows whose conditions the person meets, the highest minimum applies.
 * Pure: no database.
 */
final class MinimumWageCheck
{
    public const string OK = 'OK';

    public const string BELOW = 'BELOW';

    public const string NO_GROUP = 'NO_GROUP';

    public const string UNKNOWN_GROUP = 'UNKNOWN_GROUP';

    /** No row of the group applies to the person (for example an age the agreement data has no minimum for). */
    public const string NO_ROW = 'NO_ROW';

    /** The rows have no amount for the employment's salary form (only hourly minimums for a monthly salary, say). */
    public const string NO_BASIS = 'NO_BASIS';

    public const int MONTHS_PER_YEAR = 12;

    /**
     * @return array{status: string, age: int, experience_months: int, tenure_months: int, minimum_ore: ?int, actual_ore: ?int, basis: string, row: ?array<string, mixed>, step_from: ?string, group: ?array<string, mixed>, youth_too_old: bool}
     */
    public static function check(
        AgreementRules $rules,
        ?string $group,
        CarbonImmutable $birthDate,
        CarbonImmutable $periodEnd,
        ?CarbonImmutable $experienceFrom,
        CarbonImmutable $employmentStart,
        string $salaryForm,
        ?int $monthlySalaryOre,
        ?int $hourlyRateOre,
    ): array {
        $wages = $rules->minimumWages();
        $age = self::age($birthDate, $periodEnd, (string) ($wages['age_reference'] ?? 'PERIOD_END'));
        $experience = self::experienceMonths($experienceFrom, $birthDate, $periodEnd, isset($wages['experience_counts_from_age']) ? (int) $wages['experience_counts_from_age'] : null);
        $tenure = self::months($employmentStart, $periodEnd);
        $basis = $salaryForm === 'HOURLY' ? 'hourly_ore' : 'monthly_ore';
        $actual = $salaryForm === 'HOURLY' ? $hourlyRateOre : $monthlySalaryOre;
        $result = ['status' => self::OK, 'age' => $age, 'experience_months' => $experience, 'tenure_months' => $tenure, 'minimum_ore' => null, 'actual_ore' => $actual, 'basis' => $basis, 'row' => null, 'step_from' => null, 'group' => null, 'youth_too_old' => false];

        if ($group === null || $group === '') {
            return [...$result, 'status' => self::NO_GROUP];
        }

        $definition = $rules->group($group);

        if ($definition === null) {
            return [...$result, 'status' => self::UNKNOWN_GROUP];
        }

        $result['group'] = $definition;
        $result['youth_too_old'] = isset($definition['max_age_exclusive']) && $age >= (int) $definition['max_age_exclusive'];
        $step = $rules->minimumWageStep($periodEnd);
        $result['step_from'] = $step['valid_from'];
        $rows = array_values(array_filter($step['rows'], fn (array $row): bool => ($row['group'] ?? null) === $group
            && (! isset($row['age_min']) || $age >= (int) $row['age_min'])
            && (! isset($row['age_max']) || $age <= (int) $row['age_max'])
            && (! isset($row['experience_months_min']) || $experience >= (int) $row['experience_months_min'])
            && (! isset($row['tenure_months_min']) || $tenure >= (int) $row['tenure_months_min'])));

        if ($rows === []) {
            return [...$result, 'status' => self::NO_ROW];
        }

        $priced = array_values(array_filter($rows, fn (array $row): bool => is_int($row[$basis] ?? null)));

        if ($priced === []) {
            return [...$result, 'status' => self::NO_BASIS];
        }

        usort($priced, fn (array $a, array $b): int => (int) $b[$basis] <=> (int) $a[$basis]);
        $row = $priced[0];
        $minimum = (int) $row[$basis];

        return [
            ...$result,
            'status' => $actual !== null && $actual < $minimum ? self::BELOW : self::OK,
            'minimum_ore' => $minimum,
            'row' => $row,
        ];
    }

    /**
     * The age that counts for the period: on its last day, or, for an
     * agreement where a new age counts from the month after the birthday, on
     * the last day of the month before.
     */
    public static function age(CarbonImmutable $birthDate, CarbonImmutable $periodEnd, string $reference): int
    {
        $day = $reference === 'MONTH_AFTER_BIRTHDAY' ? $periodEnd->startOfMonth()->subDay() : $periodEnd;

        return (int) $birthDate->diffInYears($day);
    }

    /** Whole months of branschvana at the end of the period, counted from the age the agreement names when it names one. */
    public static function experienceMonths(?CarbonImmutable $from, CarbonImmutable $birthDate, CarbonImmutable $periodEnd, ?int $fromAge): int
    {
        if ($from === null) {
            return 0;
        }

        if ($fromAge !== null && $from->lessThan($birthDate->addYears($fromAge))) {
            $from = $birthDate->addYears($fromAge);
        }

        return self::months($from, $periodEnd);
    }

    private static function months(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $from->greaterThan($to) ? 0 : (int) $from->diffInMonths($to->addDay());
    }
}
