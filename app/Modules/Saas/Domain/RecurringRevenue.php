<?php

declare(strict_types=1);

namespace App\Modules\Saas\Domain;

/**
 * The recurring-revenue calculation (ADR-080 §4, rule sheet section A). Pure:
 * no database, no clock. Given the contract versions known up to a point,
 * month-end rates and a last month, it returns every month's MRR per
 * customer, the movements and the retention figures. The same input always
 * gives the same output, whatever the order of the versions.
 *
 * - MRR at the last day of the month (SA-R3), each line normalised to one
 *   month and rounded half up per line (SA-R4), in öre at the month's rate
 *   (SA-R12).
 * - At an instant the version that applies is, among the versions effective
 *   on or before it, the one known last (the highest id).
 * - Movements per customer (SA-R14): Ny, Återaktivering, Återupptagen,
 *   Utökning, Minskning, Paus, Churn; Valutaeffekt separately.
 * - Churn on the first month-end without MRR (SA-R15); a returning customer is
 *   never new and keeps its cohort (SA-R16); a pause is re-classified as churn
 *   at its 13th month-end (SA-R9, O4).
 * - NRR and GRR over 12 months at the month's rates (SA-R18).
 */
final class RecurringRevenue
{
    /** A pause counts as churn at this month-end in pause (O4: longer than 12 months). */
    public const int PAUSE_MONTHS_TO_CHURN = 13;

    /**
     * @param  list<ContractVersion>  $versions
     * @param  array<string, array<string, int>>  $rates  month (Y-m-01) => currency => kronor per unit in millionths
     * @param  ?int  $knownUntil  only versions with an id up to this one are known
     */
    public function history(array $versions, string $toMonth, array $rates = [], ?int $knownUntil = null): RevenueHistory
    {
        $byContract = [];
        $first = null;

        foreach ($versions as $version) {
            if ($knownUntil !== null && $version->id > $knownUntil) {
                continue;
            }

            $byContract[$version->contractKey()][] = $version;

            if ($version->counts()) {
                $from = Months::of(max($version->effectiveFrom, (string) $version->startsOn));
                $first = $first === null ? $from : min($first, $from);
            }
        }

        if ($first === null || $first > $toMonth) {
            return new RevenueHistory([]);
        }

        foreach ($byContract as &$list) {
            usort($list, fn (ContractVersion $a, ContractVersion $b): int => $a->id <=> $b->id);
        }
        unset($list);

        $months = [];
        /** @var array<int, array{ever: bool, cohort: ?string, state: ?CustomerState, pauseMonths: int, pausedFrom: int}> $memory */
        $memory = [];
        $previous = null;

        foreach (Months::range($first, $toMonth) as $month) {
            $result = $this->month($month, $byContract, $rates[$month] ?? [], $previous, $memory, $months);
            $months[$month] = $result;
            $previous = $result;
        }

        return new RevenueHistory($months);
    }

    /**
     * @param  array<string, list<ContractVersion>>  $byContract
     * @param  array<string, int>  $rates
     * @param  array<int, array{ever: bool, cohort: ?string, state: ?CustomerState, pauseMonths: int, pausedFrom: int}>  $memory
     * @param  array<string, MonthResult>  $done
     */
    private function month(string $month, array $byContract, array $rates, ?MonthResult $previous, array &$memory, array $done): MonthResult
    {
        $instant = Months::end($month);
        /** @var array<int, list<LineMonth>> $lines */
        $lines = [];
        $paused = [];
        $scheduled = [];
        $missing = [];
        $used = [];

        foreach ($byContract as $versions) {
            $version = self::applicable($versions, $instant);

            if ($version === null || ! $version->counts() || ! $version->inForceAt($instant)) {
                continue;
            }

            $customer = (int) $version->customerId;

            if ($version->status === ContractStatus::Paused) {
                $paused[$customer] = true;

                continue;
            }

            foreach ($version->lines as $line) {
                if ($line->usage) {
                    continue;
                }

                $micros = null;

                if ($line->currency !== 'SEK') {
                    $micros = $rates[$line->currency] ?? null;

                    if ($micros === null) {
                        $missing[$line->currency] = true;

                        continue;
                    }

                    $used[$line->currency] = $micros;
                }

                $minor = $line->monthlyMinorAt($instant);
                $ore = Arithmetic::toOre($minor, $line->currency, $micros);
                $lines[$customer][] = new LineMonth($version->sourceType, $version->sourceId, $version->id, $line->key, $line->description, $line->currency, $minor, $ore, $version->cancelEffectiveOn);

                if ($version->cancelEffectiveOn !== null) {
                    $scheduled[$customer] ??= ['ore' => 0, 'month' => null];
                    $scheduled[$customer]['ore'] += $ore;
                    $churnMonth = Months::of($version->cancelEffectiveOn);
                    $scheduled[$customer]['month'] = $scheduled[$customer]['month'] === null ? $churnMonth : min($scheduled[$customer]['month'], $churnMonth);
                }
            }
        }

        $customerIds = array_keys($lines + $paused);

        foreach ($previous->customers ?? [] as $id => $row) {
            if ($row->mrrOre > 0 || $row->state === CustomerState::Paused) {
                $customerIds[] = $id;
            }
        }

        $customerIds = array_values(array_unique($customerIds));
        sort($customerIds);
        $rows = [];

        foreach ($customerIds as $id) {
            $own = $lines[$id] ?? [];
            $before = $previous?->customers[$id] ?? null;
            $mrr = array_sum(array_map(fn (LineMonth $line): int => $line->sekOre, $own));
            $opening = $before === null ? 0 : $before->mrrOre;
            $revalued = $before === null ? 0 : array_sum(array_map(fn (LineMonth $line): int => $line->at($rates[$line->currency] ?? null), $before->lines));
            $fx = $revalued - $opening;
            $change = $mrr - $revalued;
            $remembered = $memory[$id] ?? ['ever' => false, 'cohort' => null, 'state' => null, 'pauseMonths' => 0, 'pausedFrom' => 0];
            $isPaused = $mrr === 0 && isset($paused[$id]);
            $movement = null;
            $reclass = 0;

            if ($revalued === 0 && $mrr > 0) {
                $movement = match (true) {
                    ! $remembered['ever'] => Movement::New,
                    $remembered['state'] === CustomerState::Paused => Movement::Resumed,
                    default => Movement::Reactivation,
                };
            } elseif ($revalued > 0 && $mrr > $revalued) {
                $movement = Movement::Expansion;
            } elseif ($revalued > 0 && $mrr > 0 && $mrr < $revalued) {
                $movement = Movement::Contraction;
            } elseif ($revalued > 0 && $mrr === 0) {
                $movement = $isPaused ? Movement::Pause : Movement::Churn;
            }

            if ($mrr > 0) {
                $state = CustomerState::Active;
                $remembered['ever'] = true;
                $remembered['cohort'] ??= $month;
                $remembered['pauseMonths'] = 0;
            } elseif ($isPaused) {
                $state = CustomerState::Paused;

                if ($movement === Movement::Pause) {
                    $remembered['pauseMonths'] = 1;
                    $remembered['pausedFrom'] = $revalued;
                } elseif ($remembered['state'] === CustomerState::Paused) {
                    $remembered['pauseMonths']++;
                } else {
                    // Paused from the start, or after a churn: nothing to lose, so not a pause in the bridge.
                    $state = $remembered['ever'] ? CustomerState::Churned : CustomerState::Paused;
                    $remembered['pauseMonths'] = $remembered['ever'] ? 0 : $remembered['pauseMonths'] + 1;
                }

                if ($state === CustomerState::Paused && $remembered['pauseMonths'] >= self::PAUSE_MONTHS_TO_CHURN && $remembered['ever']) {
                    $state = CustomerState::Churned;
                    $movement = Movement::Churn;
                    $reclass = $remembered['pausedFrom'];
                    $remembered['pauseMonths'] = 0;
                }
            } else {
                $state = $remembered['ever'] ? CustomerState::Churned : CustomerState::None;
            }

            $remembered['state'] = $state;
            $memory[$id] = $remembered;

            // A customer without MRR now or before, and without a pause, is not shown.
            if ($mrr === 0 && $opening === 0 && $movement === null && ! $isPaused && $own === []) {
                continue;
            }

            $rows[$id] = new CustomerMonth(
                $id,
                $month,
                $mrr,
                $opening,
                $fx,
                $movement,
                $change,
                $reclass,
                $state,
                $remembered['cohort'],
                $own,
                $scheduled[$id]['ore'] ?? 0,
                $scheduled[$id]['month'] ?? null,
            );
        }

        [$base, $nrr, $grr] = $this->retention($month, $rows, $rates, $done);
        ksort($used);
        $missing = array_keys($missing);
        sort($missing);

        return new MonthResult($month, $rows, $used, $missing, $base, $nrr, $grr);
    }

    /**
     * NRR and GRR over 12 months (SA-R18): the customers with MRR at the end
     * of m−12, their MRR then and now, both at month m's rates.
     *
     * @param  array<int, CustomerMonth>  $rows
     * @param  array<string, int>  $rates
     * @param  array<string, MonthResult>  $done
     * @return array{0: ?int, 1: ?int, 2: ?int}
     */
    private function retention(string $month, array $rows, array $rates, array $done): array
    {
        $then = $done[Months::add($month, -12)] ?? null;

        if ($then === null) {
            return [null, null, null];
        }

        $base = 0;
        $net = 0;
        $gross = 0;

        foreach ($then->customers as $id => $row) {
            if ($row->mrrOre <= 0) {
                continue;
            }

            $before = array_sum(array_map(fn (LineMonth $line): int => $line->at($rates[$line->currency] ?? null), $row->lines));
            $now = $rows[$id]->mrrOre ?? 0;
            $base += $before;
            $net += $now;
            $gross += min($now, $before);
        }

        return $base === 0 ? [null, null, null] : [$base, $net, $gross];
    }

    /**
     * The version in force for the contract at the instant: the one known
     * last among those effective on or before it.
     *
     * @param  list<ContractVersion>  $versions  sorted by id
     */
    public static function applicable(array $versions, string $instant): ?ContractVersion
    {
        $found = null;

        foreach ($versions as $version) {
            if ($version->effectiveFrom <= $instant) {
                $found = $version;
            }
        }

        return $found;
    }
}
