<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Modules\Inventory\Domain\StructureMath;

/**
 * The margin of one sales row (ADR-082 §9): what it sells for after
 * discount, what it costs, and the contribution between them. A row without
 * a known cost has no margin and is never "below" anything; a row with a
 * negative quantity or amount (a return) is not checked.
 */
final readonly class RowMargin
{
    public function __construct(
        public int $netOre,
        public ?int $costOre,
        public bool $belowCost,
        public bool $belowFloor,
    ) {}

    /**
     * @param  ?int  $floorBp  the company's lowest contribution margin; null = no floor
     */
    public static function of(int $netOre, ?int $costOre, ?int $floorBp): self
    {
        if ($costOre === null || $costOre <= 0 || $netOre < 0) {
            return new self($netOre, $costOre, false, false);
        }

        $contribution = $netOre - $costOre;

        return new self(
            $netOre,
            $costOre,
            belowCost: $netOre < $costOre,
            // contribution / net < floor, without rounding: contribution × 10 000 < floor × net.
            belowFloor: $floorBp !== null && ($netOre === 0 || $contribution * 10000 < $floorBp * $netOre),
        );
    }

    public function contributionOre(): ?int
    {
        return $this->costOre === null ? null : $this->netOre - $this->costOre;
    }

    /** The contribution margin (täckningsgrad) in basis points; null without a cost or a positive amount. */
    public function marginBp(): ?int
    {
        $contribution = $this->contributionOre();

        return $contribution === null ? null : StructureMath::share($contribution, $this->netOre);
    }
}
