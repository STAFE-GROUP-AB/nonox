<?php

declare(strict_types=1);

namespace App\Modules\Saas\Domain;

/**
 * A customer's MRR at a month-end and how it moved from the month before
 * (SA-R14): mrr = opening + fx + movement, to the öre. A pause that has
 * lasted 13 month-ends is re-classified as churn (O4): movement Churn with
 * reclassOre, which moves that amount from Paus to Churn and leaves the MRR
 * bridge unchanged.
 */
final readonly class CustomerMonth
{
    /**
     * @param  list<LineMonth>  $lines
     */
    public function __construct(
        public int $customerId,
        public string $month,
        public int $mrrOre,
        /** The MRR at the end of the month before, at that month's rate. */
        public int $openingOre,
        /** The month before's lines at this month's rate, minus openingOre (SA-R12). */
        public int $fxOre,
        public ?Movement $movement,
        /** The change in MRR at this month's rate: mrr − opening − fx. */
        public int $movementOre,
        public int $reclassOre,
        public CustomerState $state,
        public ?string $cohortMonth,
        public array $lines,
        /** MRR in force now that ends on a cancel date later (Uppsagd MRR, SA-R15). */
        public int $scheduledChurnOre,
        public ?string $scheduledChurnMonth,
    ) {}

    /** Counted in kundchurn and intäktschurn: churned from MRR above zero this month (SA-R17). */
    public function churnedFromActive(): bool
    {
        return $this->movement === Movement::Churn && $this->reclassOre === 0;
    }
}
