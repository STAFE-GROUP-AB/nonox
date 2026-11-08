<?php

declare(strict_types=1);

namespace App\Domain\Overview;

use Carbon\CarbonImmutable;

/**
 * A simple, explainable forecast of the bank balance at the end of this
 * month and the next ones (ADR-027 and its addendum of 2026-11-08). No AI
 * and no statistics model:
 *
 *   balance at month end = balance before
 *                        + customer invoices due in the month
 *                        − supplier invoices due in the month
 *                        + invoices from agreements due in the month
 *                        − net pay, withheld tax and employer contributions
 *                        − VAT to pay
 *                        ± the tax account (preliminary tax, a debt on it,
 *                          or money on it that pays the taxes)
 *                        + the average monthly result of the last three
 *                          whole months, without what the explicit flows
 *                          above already count (Statistics decides that)
 *
 * Everything already overdue counts in the first month. For the current
 * month only the days left count of the average result (the days gone by
 * are already in today's balance). Amounts are whole öre, rounded half up.
 *
 * The taxes (VAT, payroll taxes, preliminary tax) are paid through the tax
 * account: money on it and VAT to get back pay them first, and what is left
 * of that money is never counted as coming to the bank.
 *
 * @phpstan-type Flow array{due: string, amount: int}
 * @phpstan-type Flows array{recurring?: list<Flow>, payroll_net?: list<Flow>, payroll_tax?: list<Flow>, vat?: list<Flow>, preliminary_tax?: list<Flow>, tax_account?: int}
 * @phpstan-type Point array{month: string, inflow: int, outflow: int, recurring: int, payroll: int, vat: int, tax_account: int, trend: int, balance: int}
 */
final class LiquidityForecast
{
    public const int MONTHS = 3;

    /**
     * @param  list<Flow>  $receivables  open customer invoices: due date and what is left to pay
     * @param  list<Flow>  $payables  open supplier invoices, likewise
     * @param  Flows  $flows  the explicit flows: recurring (in), payroll_net and payroll_tax (out), vat (out, negative to get
     *                        back), preliminary_tax (out), all positive; tax_account the balance of 1630 today, positive when
     *                        the company has money on it
     * @return list<Point> inflow and outflow are positive amounts; recurring, payroll, vat, tax_account and trend are signed as they change the balance
     */
    public static function project(int $balance, CarbonImmutable $today, array $receivables, array $payables, int $averageResult, int $months = self::MONTHS, array $flows = []): array
    {
        $today = $today->startOfDay();
        $first = $today->startOfMonth();
        $taxAccount = $flows['tax_account'] ?? 0;
        // Money on the tax account pays the taxes as they fall due; a debt on it is paid in the first month.
        $credit = max($taxAccount, 0);
        $debt = max(-$taxAccount, 0);
        $points = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $first->addMonthsNoOverflow($i);
            $end = $month->endOfMonth()->startOfDay();
            $from = $i === 0 ? null : $month;

            $inflow = self::dueBetween($receivables, $from, $end);
            $outflow = self::dueBetween($payables, $from, $end);
            $recurring = self::dueBetween($flows['recurring'] ?? [], $from, $end);
            $net = self::dueBetween($flows['payroll_net'] ?? [], $from, $end);
            $payrollTax = self::dueBetween($flows['payroll_tax'] ?? [], $from, $end);
            $vat = self::dueBetween($flows['vat'] ?? [], $from, $end);
            $preliminary = self::dueBetween($flows['preliminary_tax'] ?? [], $from, $end) + ($i === 0 ? $debt : 0);

            // VAT to get back is credited to the tax account, not paid to the bank.
            $credit += max(-$vat, 0);
            $vatToPay = max($vat, 0);
            $used = min($credit, $vatToPay + $payrollTax + $preliminary);
            $credit -= $used;

            $trend = $i === 0
                ? (int) round($averageResult * ($end->day - $today->day + 1) / $end->day)
                : $averageResult;

            $point = [
                'month' => $month->format('Y-m'),
                'inflow' => $inflow,
                'outflow' => $outflow,
                'recurring' => $recurring,
                'payroll' => -($net + $payrollTax),
                'vat' => -$vatToPay,
                'tax_account' => $used - $preliminary,
                'trend' => $trend,
            ];

            $balance += $inflow - $outflow + $point['recurring'] + $point['payroll'] + $point['vat'] + $point['tax_account'] + $trend;
            $points[] = [...$point, 'balance' => $balance];
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
     * @param  list<Flow>  $flows
     */
    private static function dueBetween(array $flows, ?CarbonImmutable $from, CarbonImmutable $to): int
    {
        $sum = 0;

        foreach ($flows as $flow) {
            $due = CarbonImmutable::parse($flow['due'])->startOfDay();

            if ($due->greaterThan($to) || ($from !== null && $due->lessThan($from))) {
                continue;
            }

            $sum += $flow['amount'];
        }

        return $sum;
    }
}
