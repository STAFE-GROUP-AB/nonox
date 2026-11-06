<?php

declare(strict_types=1);

namespace App\Domain\IncomeTax;

/**
 * The amounts and percentages of the K10 gränsbelopp for one inkomstår
 * (ADR-062): database/data/k10/{year}.json, every value with its source URL,
 * quote and status. A year without a file, a file for another year, or a
 * parameter that is not `researched` or `verified` gives no parameters, and
 * the K10 page blocks the calculation instead of guessing.
 */
final class K10Parameters
{
    public const array REQUIRED = [
        'basic_amount_ibb_multiple',
        'income_base_amount_previous_year_ore',
        'income_base_amount_year_ore',
        'salary_deduction_ibb_multiple',
        'salary_space_share_bp',
        'salary_space_cap_multiple',
        'cost_basis_threshold_ore',
        'cost_basis_interest_add_bp',
        'statslaneranta_bp',
        'dividend_capital_share',
        'employment_income_cap_ibb_multiple',
    ];

    private const array SOURCED = ['researched', 'verified'];

    /** @param  array<string, array{value: int|array<string, int>, status: string, source_url: string, source_quote: string}>  $parameters */
    private function __construct(
        public readonly int $year,
        public readonly string $period,
        public readonly string $status,
        private readonly array $parameters,
    ) {}

    /** The parameters of an inkomstår, or null when the year is missing or any value is unsourced. */
    public static function for(int $year): ?self
    {
        $path = database_path("data/k10/{$year}.json");

        if (! is_file($path)) {
            return null;
        }

        /** @var array{income_year: int, period: string, status: string, parameters: array<string, array{value: int|array<string, int>, status: string, source_url: string, source_quote: string}>} $data */
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if ($data['income_year'] !== $year || ! in_array($data['status'], self::SOURCED, true)) {
            return null;
        }

        foreach (self::REQUIRED as $key) {
            if (! in_array($data['parameters'][$key]['status'] ?? null, self::SOURCED, true)) {
                return null;
            }
        }

        return new self($year, $data['period'], $data['status'], $data['parameters']);
    }

    /**
     * The inkomstår that have a parameter file, newest first.
     *
     * @return list<int>
     */
    public static function years(): array
    {
        $years = [];

        foreach (glob(database_path('data/k10/*.json')) ?: [] as $file) {
            $year = (int) basename($file, '.json');

            if (self::for($year) !== null) {
                $years[] = $year;
            }
        }

        rsort($years);

        return $years;
    }

    public function int(string $key): int
    {
        $value = $this->parameters[$key]['value'] ?? null;

        return is_int($value) ? $value : throw new \LogicException("Missing K10 parameter {$key} for {$this->year}.");
    }

    /** @return array{numerator: int, denominator: int} */
    public function fraction(string $key): array
    {
        $value = $this->parameters[$key]['value'] ?? null;

        if (! is_array($value) || ! is_int($value['numerator'] ?? null) || ! is_int($value['denominator'] ?? null) || $value['denominator'] <= 0) {
            throw new \LogicException("Missing K10 parameter {$key} for {$this->year}.");
        }

        return ['numerator' => $value['numerator'], 'denominator' => $value['denominator']];
    }

    public function source(string $key): string
    {
        return $this->parameters[$key]['source_url'] ?? '';
    }

    /** The grundbelopp for all shares of the company, in öre (four inkomstbasbelopp of the year before). */
    public function basicAmountOre(): int
    {
        return $this->int('basic_amount_ibb_multiple') * $this->int('income_base_amount_previous_year_ore');
    }

    /** The löneavdrag of K10 p. 4.10, in öre (eight inkomstbasbelopp of the year before). */
    public function salaryDeductionOre(): int
    {
        return $this->int('salary_deduction_ibb_multiple') * $this->int('income_base_amount_previous_year_ore');
    }

    /** The interest rate on the omkostnadsbelopp, in basis points: statslåneräntan (at least 0 %) plus nine percentage points. */
    public function costBasisInterestBp(): int
    {
        return max($this->int('statslaneranta_bp'), 0) + $this->int('cost_basis_interest_add_bp');
    }

    /** The 90 inkomstbasbelopp cap on dividend taxed as tjänst (IL 57 kap. 20 a §), in öre. */
    public function employmentIncomeCapOre(): int
    {
        return $this->int('employment_income_cap_ibb_multiple') * $this->int('income_base_amount_year_ore');
    }
}
