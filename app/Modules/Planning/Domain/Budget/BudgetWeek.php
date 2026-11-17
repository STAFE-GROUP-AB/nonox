<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Budget;

/**
 * One ISO week's budgets for a company: the whole company's own budget (if
 * any) and one line per area with a budget. The total is the company's own
 * budget, or else the sum of the areas that have one, against the cost of
 * those areas' shifts.
 */
final readonly class BudgetWeek
{
    /**
     * @param  string  $monday  Y-m-d
     * @param  array<int, BudgetLine>  $areas  area id => line
     */
    public function __construct(
        public string $monday,
        public ?BudgetLine $company,
        public array $areas,
        public int $warnAtBp,
    ) {}

    public function isEmpty(): bool
    {
        return $this->company === null && $this->areas === [];
    }

    public function total(): ?BudgetLine
    {
        if ($this->company !== null) {
            return $this->company;
        }

        $known = array_filter($this->areas, fn (BudgetLine $line): bool => $line->budgetOre !== null);

        if ($known === []) {
            return null;
        }

        return new BudgetLine(
            null,
            BudgetLine::AREAS,
            array_sum(array_map(fn (BudgetLine $line): int => (int) $line->budgetOre, $known)),
            null,
            null,
            0,
            array_sum(array_map(fn (BudgetLine $line): int => $line->costOre, $known)),
            in_array(true, array_map(fn (BudgetLine $line): bool => $line->incomplete, $known), true),
            $this->warnAtBp,
            array_values(array_map(fn (BudgetLine $line): int => (int) $line->areaId, $known)),
            uncoveredDays: max(0, ...array_map(fn (BudgetLine $line): int => $line->uncoveredDays, array_values($known))),
            uncoveredOre: array_sum(array_map(fn (BudgetLine $line): int => $line->uncoveredOre, $known)),
            areaDates: array_map(fn (BudgetLine $line): ?array => $line->forecastDates, $known),
        );
    }

    /**
     * The total first, then the areas: every line that is shown.
     *
     * @return list<BudgetLine>
     */
    public function lines(): array
    {
        $total = $this->total();

        return [...($total === null ? [] : [$total]), ...array_values($this->areas)];
    }

    /** @return list<BudgetLine> lines at or over the threshold */
    public function warnings(): array
    {
        return array_values(array_filter($this->lines(), fn (BudgetLine $line): bool => $line->warns()));
    }
}
