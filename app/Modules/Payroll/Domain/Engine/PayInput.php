<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * One input to an employee's pay for the period, with the pay code's
 * treatment copied in, so the engine never reads the database.
 */
final readonly class PayInput
{
    public function __construct(
        public string $code,
        public string $description,
        public string $category,
        public bool $taxable,
        public string $taxMethod,
        public bool $contributionBasis,
        public bool $vacationBasis,
        public ?string $quantity = null,
        public ?int $rateOre = null,
        public ?int $amountOre = null,
    ) {}

    /** The amount, or quantity × rate rounded half up to öre. */
    public function total(): int
    {
        if ($this->amountOre !== null) {
            return $this->amountOre;
        }

        return BigDecimal::of($this->quantity ?? '0')
            ->multipliedBy($this->rateOre ?? 0)
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();
    }
}
