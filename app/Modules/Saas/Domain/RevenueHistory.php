<?php

declare(strict_types=1);

namespace App\Modules\Saas\Domain;

/**
 * Every month's result, oldest first, from the first month a contract was in
 * force up to the month asked for.
 */
final readonly class RevenueHistory
{
    /**
     * @param  array<string, MonthResult>  $months  by month (Y-m-01)
     */
    public function __construct(public array $months) {}

    public function month(string $month): ?MonthResult
    {
        return $this->months[$month] ?? null;
    }

    /**
     * The cohort table as of a month (SA-R19): per cohort (the month of the
     * customer's first MRR) and per month since start, how many of the
     * cohort's customers have MRR, and the cohort's MRR, both as counts and öre
     * at the as-of month's rates (constant currency, SA-R18). A reactivated
     * customer stays in its first cohort.
     *
     * @return array<string, list<array{customers: int, mrr_ore: int}>> cohort month => [age 0, age 1, …]
     */
    public function cohorts(string $asOf): array
    {
        $target = $this->months[$asOf] ?? null;

        if ($target === null) {
            return [];
        }

        $rates = $target->rates;
        $table = [];

        foreach ($this->months as $month => $result) {
            if ($month > $asOf) {
                break;
            }

            foreach ($result->customers as $row) {
                if ($row->cohortMonth === null || $row->mrrOre <= 0) {
                    continue;
                }

                $age = Months::between($row->cohortMonth, $month);
                $constant = array_sum(array_map(fn (LineMonth $line): int => $line->at($rates[$line->currency] ?? null), $row->lines));
                $table[$row->cohortMonth] ??= [];
                $table[$row->cohortMonth][$age] ??= ['customers' => 0, 'mrr_ore' => 0];
                $table[$row->cohortMonth][$age]['customers']++;
                $table[$row->cohortMonth][$age]['mrr_ore'] += $constant;
            }
        }

        ksort($table);
        $filled = [];

        foreach ($table as $cohort => $ages) {
            $last = Months::between($cohort, $asOf);
            $row = [];

            for ($age = 0; $age <= $last; $age++) {
                $row[] = $ages[$age] ?? ['customers' => 0, 'mrr_ore' => 0];
            }

            $filled[$cohort] = $row;
        }

        return $filled;
    }
}
