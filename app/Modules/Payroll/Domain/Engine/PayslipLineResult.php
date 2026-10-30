<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

/**
 * A calculated payslip line with how it was reached.
 */
final readonly class PayslipLineResult
{
    public const string TAX_TABLE = 'TABLE';

    public const string TAX_ONE_OFF = 'ONE_OFF';

    public const string TAX_NONE = 'NONE';

    public const string NET = 'NET';

    /**
     * @param  array<string, mixed>  $explanation
     */
    public function __construct(
        public string $code,
        public string $description,
        public int $amountOre,
        public string $taxEffect,
        public bool $contributionBasis,
        public bool $vacationBasis,
        public ?string $quantity = null,
        public ?int $rateOre = null,
        public ?int $ruleVersionId = null,
        public array $explanation = [],
    ) {}
}
