<?php

declare(strict_types=1);

namespace App\Modules\Saas\Domain;

/**
 * The company's recurring revenue for one month: every customer's row and
 * the totals of the bridge, MRR(m) = MRR(m−1) + Valutaeffekt + the movement
 * categories (SA-R14), plus the 12-month retention figures (SA-R18).
 */
final readonly class MonthResult
{
    /**
     * @param  array<int, CustomerMonth>  $customers  by customer id
     * @param  array<string, int>  $rates  currency => öre per unit in millionths, the month-end rates used
     * @param  list<string>  $missingRates  currencies without a rate for the month (their lines are left out)
     */
    public function __construct(
        public string $month,
        public array $customers,
        public array $rates,
        public array $missingRates,
        public ?int $nrrBaseOre,
        public ?int $nrrOre,
        public ?int $grrOre,
    ) {}

    public function mrrOre(): int
    {
        return array_sum(array_map(fn (CustomerMonth $row): int => $row->mrrOre, $this->customers));
    }

    public function arrOre(): int
    {
        return $this->mrrOre() * 12;
    }

    public function openingOre(): int
    {
        return array_sum(array_map(fn (CustomerMonth $row): int => $row->openingOre, $this->customers));
    }

    public function fxOre(): int
    {
        return array_sum(array_map(fn (CustomerMonth $row): int => $row->fxOre, $this->customers));
    }

    /** The category's total, signed as it moves MRR; a re-classified pause moves from Paus to Churn. */
    public function movementOre(Movement $movement): int
    {
        $sum = 0;

        foreach ($this->customers as $row) {
            if ($row->movement === $movement) {
                $sum += $row->movementOre;
            }

            if ($row->reclassOre !== 0) {
                $sum += match ($movement) {
                    Movement::Churn => -$row->reclassOre,
                    Movement::Pause => $row->reclassOre,
                    default => 0,
                };
            }
        }

        return $sum;
    }

    /** Customers with MRR above zero at the month-end. */
    public function activeCustomers(): int
    {
        return count(array_filter($this->customers, fn (CustomerMonth $row): bool => $row->mrrOre > 0));
    }

    public function openingCustomers(): int
    {
        return count(array_filter($this->customers, fn (CustomerMonth $row): bool => $row->openingOre > 0));
    }

    public function customersWith(Movement $movement): int
    {
        return count(array_filter($this->customers, fn (CustomerMonth $row): bool => $row->movement === $movement));
    }

    /** Customers counted in kundchurn: churned from MRR above zero (SA-R17). */
    public function churnedCustomers(): int
    {
        return count(array_filter($this->customers, fn (CustomerMonth $row): bool => $row->churnedFromActive()));
    }

    /** Churn counted in the revenue churn rates: from customers that had MRR at the month before. */
    public function churnFromActiveOre(): int
    {
        return array_sum(array_map(fn (CustomerMonth $row): int => $row->churnedFromActive() ? $row->movementOre : 0, $this->customers));
    }

    public function scheduledChurnOre(): int
    {
        return array_sum(array_map(fn (CustomerMonth $row): int => $row->scheduledChurnOre, $this->customers));
    }

    /** True when MRR = opening + fx + every category, to the öre. */
    public function bridgeHolds(): bool
    {
        $movements = array_sum(array_map(fn (Movement $movement): int => $this->movementOre($movement), Movement::cases()));

        return $this->mrrOre() === $this->openingOre() + $this->fxOre() + $movements;
    }
}
