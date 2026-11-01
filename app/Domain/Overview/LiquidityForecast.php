<?php

declare(strict_types=1);

namespace App\Domain\Overview;

use Carbon\CarbonImmutable;

/**
 * A simple, explainable forecast of the bank balance at the end of this
 * month and the next ones (ADR-027). No AI and no statistics model:
 *
 *   balance at month end = balance before
 *                        + customer invoices due in the month
 *                        − supplier invoices due in the month
 *                        + the average monthly result of the last three
 *                          whole months
 *
 * Invoices already overdue count in the first month. For the current month
 * only the days left count of the average result (the days gone by are
 * already in today's balance). Amounts are whole öre, rounded half up.
 */
final class LiquidityForecast
{
    public const int MONTHS = 3;

    /**
     * @param  list<array{due: string, amount: int}>  $receivables  open customer invoices: due date and what is left to pay
     * @param  list<array{due: string, amount: int}>  $payables  open supplier invoices, likewise
     * @return list<array{month: string, inflow: int, outflow: int, trend: int, balance: int}>
     */
    public static function project(int $balance, CarbonImmutable $today, array $receivables, array $payables, int $averageResult, int $months = self::MONTHS): array
    {
        $today = $today->startOfDay();
        $first = $today->startOfMonth();
        $points = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $first->addMonthsNoOverflow($i);
            $end = $month->endOfMonth()->startOfDay();
            $from = $i === 0 ? null : $month;

            $inflow = self::dueBetween($receivables, $from, $end);
            $outflow = self::dueBetween($payables, $from, $end);
            $trend = $i === 0
                ? (int) round($averageResult * ($end->day - $today->day + 1) / $end->day)
                : $averageResult;

            $balance += $inflow - $outflow + $trend;

            $points[] = [
                'month' => $month->format('Y-m'),
                'inflow' => $inflow,
                'outflow' => $outflow,
                'trend' => $trend,
                'balance' => $balance,
            ];
        }

        return $points;
    }

    /**
     * The average of the results of the last whole months, rounded half up;
     * zero without any.
     *
     * @param  list<int>  $results
     */
    public static function averageResult(array $results): int
    {
        return $results === [] ? 0 : (int) round(array_sum($results) / count($results));
    }

    /**
     * What falls due up to the end of the month; from its start, or with
     * everything already overdue when no start is given (the first month).
     *
     * @param  list<array{due: string, amount: int}>  $invoices
     */
    private static function dueBetween(array $invoices, ?CarbonImmutable $from, CarbonImmutable $to): int
    {
        $sum = 0;

        foreach ($invoices as $invoice) {
            $due = CarbonImmutable::parse($invoice['due'])->startOfDay();

            if ($due->greaterThan($to) || ($from !== null && $due->lessThan($from))) {
                continue;
            }

            $sum += $invoice['amount'];
        }

        return $sum;
    }
}
