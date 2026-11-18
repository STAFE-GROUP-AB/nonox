<?php

declare(strict_types=1);

namespace App\Domain\Budget;

/**
 * How a forecast fills the months after its cut-off: one method for revenue
 * rows and one for cost rows (owner decision 7: the active budget for
 * revenue, the average of the last 3 closed months for costs), a method of
 * its own on any row, the number of months in the average and the growth on
 * last year. Stored as the version's `method` jsonb, with ids and enums only.
 */
final readonly class ForecastSettings
{
    public const int DEFAULT_AVERAGE_MONTHS = 3;

    public const int MAX_AVERAGE_MONTHS = 12;

    /**
     * @param  array<string, ForecastMethod>  $rowMethods  row key ("account-costcentre-project", 0 for none) => method
     */
    public function __construct(
        public ForecastMethod $revenue = ForecastMethod::Budget,
        public ForecastMethod $costs = ForecastMethod::Average,
        public int $averageMonths = self::DEFAULT_AVERAGE_MONTHS,
        public int $growthBasisPoints = 0,
        public array $rowMethods = [],
        public ?int $budgetVersionId = null,
    ) {}

    /** The method of one row: its own, else the one for revenue or costs. */
    public function methodFor(string $rowKey, bool $revenue): ForecastMethod
    {
        return $this->rowMethods[$rowKey] ?? ($revenue ? $this->revenue : $this->costs);
    }

    /** Whether any row, or either default, reads the budget. */
    public function usesBudget(): bool
    {
        return $this->revenue === ForecastMethod::Budget
            || $this->costs === ForecastMethod::Budget
            || in_array(ForecastMethod::Budget, $this->rowMethods, true);
    }

    public function withBudget(?int $budgetVersionId): self
    {
        return new self($this->revenue, $this->costs, $this->averageMonths, $this->growthBasisPoints, $this->rowMethods, $budgetVersionId);
    }

    /** @param  array<string, mixed>|null  $stored */
    public static function fromArray(?array $stored): self
    {
        $stored ??= [];
        $rows = [];

        foreach ((array) ($stored['rows'] ?? []) as $key => $method) {
            $case = is_string($method) ? ForecastMethod::tryFrom($method) : null;

            if ($case !== null && preg_match('/^\d+-\d+-\d+$/', (string) $key) === 1) {
                $rows[(string) $key] = $case;
            }
        }

        $months = (int) ($stored['average_months'] ?? self::DEFAULT_AVERAGE_MONTHS);

        return new self(
            ForecastMethod::tryFrom((string) ($stored['revenue'] ?? '')) ?? ForecastMethod::Budget,
            ForecastMethod::tryFrom((string) ($stored['costs'] ?? '')) ?? ForecastMethod::Average,
            max(1, min(self::MAX_AVERAGE_MONTHS, $months)),
            (int) ($stored['growth_bp'] ?? 0),
            $rows,
            isset($stored['budget_version_id']) ? (int) $stored['budget_version_id'] : null,
        );
    }

    /**
     * @return array{revenue: string, costs: string, average_months: int, growth_bp: int, rows: array<string, string>, budget_version_id: ?int}
     */
    public function toArray(): array
    {
        $rows = array_map(fn (ForecastMethod $method): string => $method->value, $this->rowMethods);
        ksort($rows);

        return [
            'revenue' => $this->revenue->value,
            'costs' => $this->costs->value,
            'average_months' => $this->averageMonths,
            'growth_bp' => $this->growthBasisPoints,
            'rows' => $rows,
            'budget_version_id' => $this->budgetVersionId,
        ];
    }
}
