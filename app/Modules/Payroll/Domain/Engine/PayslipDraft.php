<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

/**
 * The payslip while the steps build it. Each step adds lines and totals;
 * nothing is stored here.
 */
final class PayslipDraft
{
    /** @var list<PayslipLineResult> */
    public array $lines = [];

    public int $taxOre = 0;

    public int $employerContributionOre = 0;

    public int $netOre = 0;

    /** @var array<string, mixed> */
    public array $taxExplanation = [];

    /** @var array<string, mixed> */
    public array $contributionExplanation = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var array<string, int> calendar-year accumulator changes */
    public array $accumulatorDeltas = [];

    public function add(PayslipLineResult $line): void
    {
        $this->lines[] = $line;
    }

    public function gross(): int
    {
        return $this->sum(fn (PayslipLineResult $line): bool => $line->taxEffect !== PayslipLineResult::NET);
    }

    public function taxableRegular(): int
    {
        return $this->sum(fn (PayslipLineResult $line): bool => $line->taxEffect === PayslipLineResult::TAX_TABLE);
    }

    public function taxableOneOff(): int
    {
        return $this->sum(fn (PayslipLineResult $line): bool => $line->taxEffect === PayslipLineResult::TAX_ONE_OFF);
    }

    public function contributionBasis(): int
    {
        return $this->sum(fn (PayslipLineResult $line): bool => $line->contributionBasis);
    }

    public function netItems(): int
    {
        return $this->sum(fn (PayslipLineResult $line): bool => $line->taxEffect === PayslipLineResult::NET);
    }

    /**
     * Cash paid before tax: everything that is not a net item or a benefit
     * (a benefit is taxed but not paid out).
     */
    public function cashBeforeTax(): int
    {
        return $this->sum(fn (PayslipLineResult $line): bool => $line->taxEffect !== PayslipLineResult::NET && ! ($line->explanation['benefit'] ?? false));
    }

    private function sum(callable $filter): int
    {
        return array_sum(array_map(fn (PayslipLineResult $line): int => $line->amountOre, array_values(array_filter($this->lines, $filter))));
    }
}
