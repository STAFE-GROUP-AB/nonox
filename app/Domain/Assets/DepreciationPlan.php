<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use Carbon\CarbonImmutable;

/**
 * A linear depreciation plan (planenlig avskrivning): the amount to write
 * off, acquisition value less residual value, spread evenly over the useful
 * life from the first month of depreciation. For each month the plan says
 * how much should have been written off by its end; booking the difference
 * to what has been written off keeps the rounding exact and lets a missed
 * month be caught up.
 *
 * On the carrying-amount basis (a component created by a split, ADR-059
 * A.2.4) the plan starts from what was already written off ($openingOre) and
 * spreads only the rest over the remaining life from the split, so nothing
 * before the split is ever caught up (K3 35.18). A plan without a life (land,
 * S1-R3.2) writes off nothing.
 *
 * Additions made after depreciation started ($additions, FixedAsset::plan)
 * are part of the acquisition value but each is written off linearly from
 * its own first month over its own (remaining) life, so the months before it
 * are never caught up. The base plan spreads the acquisition value less those
 * additions.
 *
 * TODO(legal-verify): the tax depreciation of inventarier
 * (räkenskapsenlig avskrivning, 30- and 20-regeln) and any overavskrivning
 * on 2150/8850 are not handled; the register follows the plan only.
 */
final readonly class DepreciationPlan
{
    public function __construct(
        public int $acquisitionOre,
        public int $residualOre,
        public int $lifeMonths,
        public CarbonImmutable $start,
        public int $openingOre = 0,
        public DepreciationBasis $basis = DepreciationBasis::Cost,
        /** @var list<array{amount: int, start: CarbonImmutable, life: int}> */
        public array $additions = [],
    ) {}

    /** All the plan writes off over the asset's life, additions included; nothing for land. */
    public function depreciableOre(): int
    {
        if ($this->lifeMonths <= 0) {
            return 0;
        }

        return max(0, $this->acquisitionOre - $this->residualOre);
    }

    /** What should have been written off by the end of the month (YYYY-MM). */
    public function accumulatedBy(string $month): int
    {
        $index = $this->monthIndex($month);

        $base = $this->basis === DepreciationBasis::CarryingAmount
            ? $this->carryingAccumulatedBy($index)
            : $this->linear($this->baseDepreciableOre(), $index, $this->lifeMonths);

        if ($this->lifeMonths <= 0) {
            return $base;
        }

        foreach ($this->additions as $addition) {
            $base += $this->linear($addition['amount'], $this->monthIndex($month, $addition['start']), $addition['life']);
        }

        return $base;
    }

    /** What the base plan writes off: the acquisition value less the separately planned additions, less residual. */
    private function baseDepreciableOre(): int
    {
        if ($this->lifeMonths <= 0) {
            return 0;
        }

        return max(0, $this->acquisitionOre - array_sum(array_column($this->additions, 'amount')) - $this->residualOre);
    }

    /** An amount spread evenly over a life, as written off by the end of the month at the index. */
    private function linear(int $amount, int $index, int $life): int
    {
        if ($index < 0 || $life <= 0) {
            return 0;
        }

        $months = min($index + 1, $life);

        return intdiv($amount * $months + intdiv($life, 2), $life);
    }

    /** The amount to book for a month, given what has been written off before it. */
    public function amountFor(string $month, int $accumulatedBefore): int
    {
        return max(0, $this->accumulatedBy($month) - $accumulatedBefore);
    }

    public function lastMonth(): string
    {
        return $this->start->startOfMonth()->addMonthsNoOverflow(max(0, $this->lifeMonths - 1))->format('Y-m');
    }

    /**
     * The plan month by month: the month, the depreciation and the book
     * value at its end. An addition counts in the book value from the month
     * before it starts to be written off, the month it is normally made.
     *
     * @return list<array{month: string, amount: int, book_value: int}>
     */
    public function schedule(): array
    {
        $rows = [];
        $before = $this->basis === DepreciationBasis::CarryingAmount ? $this->carryingAccumulatedBy(-1) : 0;

        for ($i = 0; $i < $this->lifeMonths; $i++) {
            $month = $this->start->startOfMonth()->addMonthsNoOverflow($i)->format('Y-m');
            $after = $this->accumulatedBy($month);
            $later = array_sum(array_map(fn (array $addition): int => $this->monthIndex($month, $addition['start']) < -1 ? $addition['amount'] : 0, $this->additions));
            $rows[] = ['month' => $month, 'amount' => $after - $before, 'book_value' => $this->acquisitionOre - $later - $after];
            $before = $after;
        }

        return $rows;
    }

    /**
     * The carrying-amount basis: what was written off at the split plus the
     * rest (cost less residual less that) spread over the remaining months.
     */
    private function carryingAccumulatedBy(int $index): int
    {
        $opening = min($this->openingOre, $this->baseDepreciableOre());

        if ($index < 0 || $this->lifeMonths <= 0) {
            return $opening;
        }

        $rest = $this->baseDepreciableOre() - $opening;
        $months = min($index + 1, $this->lifeMonths);

        return $opening + intdiv($rest * $months + intdiv($this->lifeMonths, 2), $this->lifeMonths);
    }

    private function monthIndex(string $month, ?CarbonImmutable $start = null): int
    {
        $target = CarbonImmutable::parse("{$month}-01");
        $first = ($start ?? $this->start)->startOfMonth();

        return ($target->year - $first->year) * 12 + ($target->month - $first->month);
    }
}
