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
        /** @var array<int, array{ever: bool, cohort: ?string, state: ?CustomerState, pauseMonths: int, pausedParts: array<string, int>}> $memory */
        $memory = [];
        $previous = null;
        $carried = [];

        foreach (Months::range($first, $toMonth) as $month) {
            // A month without a rate for a currency keeps the last rate known, and says so (SA-R12).
            $given = $rates[$month] ?? [];
            $effective = $given + $carried;
            $result = $this->month($month, $byContract, $effective, array_keys(array_diff_key($carried, $given)), $previous, $memory, $months);
            $months[$month] = $result;
            $previous = $result;
            $carried = $effective;
        }

        return new RevenueHistory($months);
    }

    /**
     * @param  array<string, list<ContractVersion>>  $byContract
     * @param  array<string, int>  $rates  the month's rates, a missing one carried from an earlier month
     * @param  list<string>  $carried  currencies whose rate is carried, not the month's own
     * @param  array<int, array{ever: bool, cohort: ?string, state: ?CustomerState, pauseMonths: int, pausedParts: array<string, int>}>  $memory
     * @param  array<string, MonthResult>  $done
     */
    private function month(string $month, array $byContract, array $rates, array $carried, ?MonthResult $previous, array &$memory, array $done): MonthResult
    {
        $instant = Months::end($month);
        /** @var array<int, list<LineMonth>> $lines */
        $lines = [];
        /** @var array<int, array<string, true>> $paused contract keys in force and paused, per customer */
        $paused = [];
        /** @var array<string, int> $sourceOff contract key => customer: in force, but its source is switched off (CRM_OFF) */
        $sourceOff = [];
        $scheduled = [];
        $missing = [];
        $used = [];

        foreach ($byContract as $key => $versions) {
            $version = self::applicable($versions, $instant);

            if ($version === null || ! $version->inForceAt($instant) || $version->customerId === null) {
                continue;
            }

            if ($version->excluded === ExclusionReason::CrmOff) {
                $sourceOff[$key] = $version->customerId;

                continue;
            }

            if (! $version->counts()) {
                continue;
            }

            $customer = $version->customerId;

            if ($version->status === ContractStatus::Paused) {
                $paused[$customer][$key] = true;

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

                    if (in_array($line->currency, $carried, true)) {
                        $missing[$line->currency] = true;
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

        $wasOff = $previous->sourceOff ?? [];
        $customerIds = array_keys($lines + $paused);

        foreach ($previous->customers ?? [] as $id => $row) {
            if ($row->mrrOre > 0 || $row->state === CustomerState::Paused) {
                $customerIds[] = $id;
            }
        }

        $customerIds = array_values(array_unique($customerIds));
        sort($customerIds);
        $rows = [];
        $negative = [];

        foreach ($customerIds as $id) {
            $own = $lines[$id] ?? [];
            $before = $previous?->customers[$id] ?? null;
            $sum = array_sum(array_map(fn (LineMonth $line): int => $line->sekOre, $own));

            // A customer whose lines add up to less than zero counts as 0 and is listed under Källor.
            if ($sum < 0) {
                $negative[] = $id;
            }

            $byKey = [];

            foreach ($before->lines ?? [] as $line) {
                $key = $line->sourceType->value.':'.$line->sourceId;
                $byKey[$key] = ($byKey[$key] ?? 0) + $line->at($rates[$line->currency] ?? null);
            }

            // A source switched off or on again is not a customer moving (review B): its own category.
            $sourceOut = array_sum(array_intersect_key($byKey, $sourceOff));
            $sourceIn = 0;

            foreach ($own as $line) {
                if (isset($wasOff[$line->sourceType->value.':'.$line->sourceId])) {
                    $sourceIn += $line->sekOre;
                }
            }

            $mrr = max(0, $sum);
            $opening = $before === null ? 0 : $before->mrrOre;
            $revalued = $before === null ? 0 : max(0, array_sum($byKey));
            $fx = $revalued - $opening;
            $compared = max(0, array_sum($byKey) - $sourceOut + $sourceIn);
            $source = $compared - $revalued;
            $change = $mrr - $compared;
            $remembered = $memory[$id] ?? ['ever' => false, 'cohort' => null, 'state' => null, 'pauseMonths' => 0, 'pausedParts' => []];
            $pausedNow = $paused[$id] ?? [];
            $isPaused = $mrr === 0 && $pausedNow !== [];
            $movement = null;
            $reclass = 0;

            if ($compared === 0 && $mrr > 0) {
                $movement = match (true) {
                    // Back from 0 without ever leaving: clamped (review P3) or only a source switched off.
                    $remembered['state'] === CustomerState::Active => Movement::Expansion,
                    ! $remembered['ever'] => Movement::New,
                    $remembered['state'] === CustomerState::Paused => Movement::Resumed,
                    default => Movement::Reactivation,
                };
            } elseif ($compared > 0 && $mrr > $compared) {
                $movement = Movement::Expansion;
            } elseif ($compared > 0 && $mrr > 0 && $mrr < $compared) {
                $movement = Movement::Contraction;
            } elseif ($compared > 0 && $mrr === 0) {
                // SA-R14: Paus only when every line that ended was paused; a line that simply ended makes it Churn.
                $ending = array_values(array_diff(array_keys($byKey), array_keys($sourceOff)));
                $allPaused = $ending !== [] && array_diff($ending, array_keys($pausedNow)) === [];
                $movement = match (true) {
                    // Lines adding up to less than zero are clamped to 0: a contraction, not a lost customer.
                    $sum < 0 => Movement::Contraction,
                    $allPaused => Movement::Pause,
                    default => Movement::Churn,
                };

                if ($movement === Movement::Pause) {
                    $remembered['pausedParts'] = array_intersect_key($byKey, array_flip($ending));
                }
            }

            if ($mrr > 0) {
                $state = CustomerState::Active;
                $remembered['ever'] = true;
                $remembered['cohort'] ??= $month;
                $remembered['pauseMonths'] = 0;
                $remembered['pausedParts'] = [];
            } elseif ($movement === Movement::Churn) {
                $state = CustomerState::Churned;
                $remembered['pauseMonths'] = 0;
                $remembered['pausedParts'] = [];
            } elseif ($movement === Movement::Contraction || ($compared === 0 && $revalued > 0)) {
                // Clamped to 0, or only switched-off sources: the customer has not left.
                $state = $remembered['state'] ?? CustomerState::Active;
            } elseif ($isPaused) {
                $state = CustomerState::Paused;

                if ($movement === Movement::Pause) {
                    $remembered['pauseMonths'] = 1;
                } elseif ($remembered['state'] === CustomerState::Paused) {
                    $remembered['pauseMonths']++;

                    // One of several paused contracts ends: its amount is churned now, the rest stays paused (review P3).
                    $ended = array_diff_key($remembered['pausedParts'], $pausedNow);

                    if ($ended !== [] && $remembered['ever']) {
                        $movement = Movement::Churn;
                        $reclass = array_sum($ended);
                        $remembered['pausedParts'] = array_intersect_key($remembered['pausedParts'], $pausedNow);
                    }
                } else {
                    // Paused from the start, or after a churn: nothing to lose, so not a pause in the bridge.
                    $state = $remembered['ever'] ? CustomerState::Churned : CustomerState::Paused;
                    $remembered['pauseMonths'] = $remembered['ever'] ? 0 : $remembered['pauseMonths'] + 1;
                }

                if ($state === CustomerState::Paused && $remembered['pauseMonths'] >= self::PAUSE_MONTHS_TO_CHURN && $remembered['ever']) {
                    $state = CustomerState::Churned;
                    $movement = Movement::Churn;
                    $reclass += array_sum($remembered['pausedParts']);
                    $remembered['pauseMonths'] = 0;
                    $remembered['pausedParts'] = [];
                }
            } elseif ($remembered['state'] === CustomerState::Paused && $remembered['ever']) {
                // The paused contracts end while paused: what was paused is churned now (moves from Paus to Churn).
                $state = CustomerState::Churned;
                $movement = Movement::Churn;
                $reclass = array_sum($remembered['pausedParts']);
                $remembered['pauseMonths'] = 0;
                $remembered['pausedParts'] = [];
            } else {
                $state = $remembered['ever'] ? CustomerState::Churned : CustomerState::None;
            }

            $remembered['state'] = $state;
            $memory[$id] = $remembered;

            // A customer without MRR now or before, and without a pause, is not shown.
            if ($mrr === 0 && $opening === 0 && $movement === null && ! $isPaused && $own === [] && $source === 0) {
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
                $source,
            );
        }

        // A customer kept "active" only by a switched-off source, or by lines clamped to 0, whose
        // contracts have all ended has left: churned silently, since the amount already went
        // through the source row or as a contraction; a return is then a reactivation (re-check 2 D).
        $offCustomers = array_flip(array_values($sourceOff));

        foreach ($memory as $id => $remembered) {
            if ($remembered['state'] === CustomerState::Active && ! isset($lines[$id]) && ! isset($paused[$id]) && ! isset($offCustomers[$id])) {
                $memory[$id]['state'] = CustomerState::Churned;

                if (isset($rows[$id]) && $rows[$id]->mrrOre === 0) {
                    $row = $rows[$id];
                    $rows[$id] = new CustomerMonth($row->customerId, $row->month, $row->mrrOre, $row->openingOre, $row->fxOre, $row->movement, $row->movementOre, $row->reclassOre, CustomerState::Churned, $row->cohortMonth, $row->lines, $row->scheduledChurnOre, $row->scheduledChurnMonth, $row->sourceOre);
                }
            }
        }

        [$base, $nrr, $grr] = $this->retention($month, $rows, $rates, $done, $sourceOff);
        ksort($used);
        $missing = array_keys($missing);
        sort($missing);

        return new MonthResult($month, $rows, $used, $missing, $base, $nrr, $grr, $negative, $sourceOff);
    }

    /**
     * NRR and GRR over 12 months (SA-R18): the customers with MRR at the end
     * of m−12, their MRR then and now, both at month m's rates. A contract
     * whose source was switched off then or now is left out of both sides,
     * since that is not retention (review B).
     *
     * @param  array<int, CustomerMonth>  $rows
     * @param  array<string, int>  $rates
     * @param  array<string, MonthResult>  $done
     * @param  array<string, int>  $sourceOff
     * @return array{0: ?int, 1: ?int, 2: ?int}
     */
    private function retention(string $month, array $rows, array $rates, array $done, array $sourceOff): array
    {
        $then = $done[Months::add($month, -12)] ?? null;

        if ($then === null) {
            return [null, null, null];
        }

        $off = $sourceOff + $then->sourceOff;
        $kept = fn (LineMonth $line): bool => ! isset($off[$line->sourceType->value.':'.$line->sourceId]);
        $base = 0;
        $net = 0;
        $gross = 0;

        foreach ($then->customers as $id => $row) {
            if ($row->mrrOre <= 0) {
                continue;
            }

            $before = max(0, array_sum(array_map(fn (LineMonth $line): int => $line->at($rates[$line->currency] ?? null), array_filter($row->lines, $kept))));
            $now = isset($rows[$id]) ? max(0, array_sum(array_map(fn (LineMonth $line): int => $line->sekOre, array_filter($rows[$id]->lines, $kept)))) : 0;
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
