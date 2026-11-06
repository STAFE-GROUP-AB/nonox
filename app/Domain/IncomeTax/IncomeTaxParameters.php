<?php

declare(strict_types=1);

namespace App\Domain\IncomeTax;

/**
 * The year parameters for the sole trader's tax calculations on the
 * NE-bilaga (ADR-053): statslåneränta and the räntefördelning rates, the
 * periodiseringsfond share, the expansionsfond cap, and the egenavgifter
 * schablon percentages. Data, not code: database/data/income-tax/{year}.json,
 * one file per inkomstår, every value with its source. A year without a
 * file, or a file not marked verified, gives no parameters, and the page
 * blocks the calculations that need them instead of guessing.
 */
final class IncomeTaxParameters
{
    /** @param  array<string, array{value: int|array<string, int>, source_url: string, source_quote: string}>  $parameters */
    private function __construct(public readonly int $year, private readonly array $parameters) {}

    /** The parameters of an inkomstår, or null when they are missing or not verified. */
    public static function for(int $year): ?self
    {
        $path = config('nonox.income_tax_data_path', database_path('data/income-tax'))."/{$year}.json";

        if (! is_file($path)) {
            return null;
        }

        /** @var array{year: int, verified: bool, parameters: array<string, array{value: int|array<string, int>, source_url: string, source_quote: string}>} $data */
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $data['verified'] === true && $data['year'] === $year ? new self($year, $data['parameters']) : null;
    }

    public function int(string $key): int
    {
        $value = $this->parameters[$key]['value'] ?? null;

        return is_int($value) ? $value : throw new \LogicException("Missing income tax parameter {$key} for {$this->year}.");
    }

    /** @return array<string, int> */
    public function map(string $key): array
    {
        $value = $this->parameters[$key]['value'] ?? null;

        return is_array($value) ? $value : throw new \LogicException("Missing income tax parameter {$key} for {$this->year}.");
    }

    public function source(string $key): string
    {
        return $this->parameters[$key]['source_url'] ?? '';
    }

    /** The statslåneränta at the end of November the year before, in basis points. */
    public function governmentRate(): int
    {
        return $this->int('statslaneranta_bp');
    }

    /** IL 33 kap. 3 §: statslåneräntan, at least 0 %, plus six percentage points. */
    public function positiveAllocationRate(): int
    {
        return max($this->governmentRate(), 0) + $this->int('interest_allocation_positive_add_bp');
    }

    /** IL 33 kap. 3 §: statslåneräntan, at least 0,5 %, plus one percentage point. */
    public function negativeAllocationRate(): int
    {
        return max($this->governmentRate(), $this->int('interest_allocation_negative_floor_bp')) + $this->int('interest_allocation_negative_add_bp');
    }
}
