<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\PriceFloorApproval;

/**
 * Where a document stands against the company's price floor (ADR-082 §9):
 * whether its manual prices need an approval, which rows, and the approval
 * of the version it has now, if any.
 */
final readonly class PriceFloorState
{
    /**
     * @param  list<int>  $lines  the line numbers (from 1) of the manual prices under the floor
     */
    public function __construct(
        public bool $needed,
        public array $lines,
        public ?PriceFloorApproval $approval,
        public PriceFloor $floor,
    ) {}

    public static function notNeeded(PriceFloor $floor): self
    {
        return new self(false, [], null, $floor);
    }

    public function approved(): bool
    {
        return $this->approval?->status === PriceFloorApprovalStatus::Approved;
    }

    public function pending(): bool
    {
        return $this->approval?->status === PriceFloorApprovalStatus::Pending;
    }

    public function rejected(): bool
    {
        return $this->approval?->status === PriceFloorApprovalStatus::Rejected;
    }

    /** Whether the document may be sent or issued as far as the floor goes. */
    public function cleared(): bool
    {
        return ! $this->needed || $this->approved();
    }
}
