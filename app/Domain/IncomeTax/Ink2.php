<?php

declare(strict_types=1);

namespace App\Domain\IncomeTax;

use App\Domain\AnnualReport\AnnualAccounts;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\IncomeTaxReturn;
use Illuminate\Validation\ValidationException;

/**
 * The income tax return of a limited company (INK2 with INK2R and INK2S)
 * from the books: the balance sheet and income statement by field code in
 * whole kronor, the book result, the tax, the adjustments a person entered
 * and the taxable surplus or deficit that follows.
 *
 * TODO(legal-verify): only the adjustments listed in Ink2Codes::ADJUSTMENTS
 * are supported; periodiseringsfond schablonintäkt, delägarrätter, andelar i
 * handelsbolag and the other INK2S points must be added by hand in
 * Skatteverket's service.
 */
final class Ink2
{
    public function __construct(
        public readonly Company $company,
        public readonly FiscalYear $fiscalYear,
        private readonly AnnualAccounts $accounts,
        private readonly ?IncomeTaxReturn $return,
    ) {}

    public static function for(Company $company, FiscalYear $fiscalYear, ?IncomeTaxReturn $return): self
    {
        return new self($company, $fiscalYear, AnnualAccounts::for($fiscalYear), $return);
    }

    /** The beskattningsperiod by the last day of the fiscal year, e.g. 2026P4. */
    public function period(): string
    {
        $end = $this->fiscalYear->end_date;
        $part = match (true) {
            $end->month <= 4 => 1,
            $end->month <= 6 => 2,
            $end->month <= 8 => 3,
            default => 4,
        };

        return "{$end->year}P{$part}";
    }

    public function isVerifiedPeriod(): bool
    {
        return in_array($this->period(), Ink2Codes::VERIFIED_PERIODS, true);
    }

    /**
     * The identity of a company in SRU: 16 and the ten digits of the
     * organisation number.
     *
     * TODO(legal-verify): confirm the century prefix 16 for legal persons.
     */
    public function identity(): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $this->company->org_number);

        if (strlen($digits) !== 10) {
            throw ValidationException::withMessages(['org_number' => __('Företagets organisationsnummer behövs för deklarationen.')]);
        }

        return "16{$digits}";
    }

    /** @return array<int, int|string> INK2R: code => whole kronor, dates as YYYYMMDD */
    public function ink2r(): array
    {
        $fields = $this->dates();

        foreach (Ink2Codes::ASSETS as $code => $ranges) {
            $fields[$code] = self::kronor($this->sumOf($ranges));
        }

        foreach (Ink2Codes::LIABILITIES as $code => $ranges) {
            $fields[$code] = self::kronor(-$this->sumOf($ranges));
        }

        // Fritt eget kapital with the year's result, whether or not it has been booked to 2099.
        $fields[Ink2Codes::FREE_EQUITY] = self::kronor(-$this->accounts->closingOf(2090, 2099) - $this->accounts->movementOf(3000, 8999));

        foreach (Ink2Codes::INCOME as $line) {
            $amount = -$this->accounts->movementOf($line['range'][0], $line['range'][1]);
            $code = $amount >= 0 ? $line['income'] : $line['cost'];

            if ($code === null) {
                // A range with only one side on the form keeps its sign, e.g. a credit on a cost account.
                $code = $line['income'] ?? $line['cost'];
                $amount = $line['income'] === null ? -$amount : $amount;
                $fields[$code] = ($fields[$code] ?? 0) + self::kronor($amount);

                continue;
            }

            $fields[$code] = ($fields[$code] ?? 0) + self::kronor(abs($amount));
        }

        $result = $this->resultKronor();
        $fields[$result >= 0 ? Ink2Codes::PROFIT : Ink2Codes::LOSS] = abs($result);

        return self::withoutZeros($fields);
    }

    /** @return array<int, int|string> INK2S: code => whole kronor, or X for the yes/no boxes */
    public function ink2s(): array
    {
        $fields = $this->dates();
        $result = $this->resultKronor();
        $fields[$result >= 0 ? Ink2Codes::S_PROFIT : Ink2Codes::S_LOSS] = abs($result);
        $fields[Ink2Codes::S_TAX] = $this->taxKronor();

        foreach ($this->adjustments() as $code => $amount) {
            $fields[$code] = $amount;
        }

        $taxable = $this->taxableKronor();
        $fields[$taxable >= 0 ? Ink2Codes::S_SURPLUS : Ink2Codes::S_DEFICIT] = abs($taxable);
        $fields = self::withoutZeros($fields);

        if ($this->return?->consultant !== null) {
            $fields[$this->return->consultant ? Ink2Codes::CONSULTANT_YES : Ink2Codes::CONSULTANT_NO] = 'X';
        }

        if ($this->return?->audited !== null) {
            $fields[$this->return->audited ? Ink2Codes::AUDITED_YES : Ink2Codes::AUDITED_NO] = 'X';
        }

        return $fields;
    }

    /** @return array<int, int|string> INK2 page 1: the dates and the taxable surplus or deficit */
    public function ink2(): array
    {
        $taxable = $this->taxableKronor();

        return self::withoutZeros([...$this->dates(), $taxable >= 0 ? Ink2Codes::SURPLUS : Ink2Codes::DEFICIT => abs($taxable)]);
    }

    /** The year's book result in whole kronor; positive is a profit. */
    public function resultKronor(): int
    {
        return self::kronor(-$this->accounts->movementOf(3000, 8989));
    }

    public function taxKronor(): int
    {
        return self::kronor($this->accounts->movementOf(8900, 8989));
    }

    /** The book result with the tax added back and the adjustments applied. */
    public function taxableKronor(): int
    {
        $taxable = $this->resultKronor() + $this->taxKronor();

        foreach ($this->adjustments() as $code => $amount) {
            $taxable += Ink2Codes::ADJUSTMENTS[$code][1] * $amount;
        }

        return $taxable;
    }

    /** @return array<int, int> code => whole kronor, only the ones entered */
    public function adjustments(): array
    {
        $entered = $this->return->adjustments ?? [];
        $adjustments = [];

        foreach (array_keys(Ink2Codes::ADJUSTMENTS) as $code) {
            $amount = $entered[$code] ?? 0;

            if ($amount !== 0) {
                $adjustments[$code] = $amount;
            }
        }

        return $adjustments;
    }

    /** @return array<int, string> */
    private function dates(): array
    {
        return [7011 => $this->fiscalYear->start_date->format('Ymd'), 7012 => $this->fiscalYear->end_date->format('Ymd')];
    }

    /** @param  list<array{0: int, 1: int}>  $ranges */
    private function sumOf(array $ranges): int
    {
        return array_sum(array_map(fn (array $range): int => $this->accounts->closingOf($range[0], $range[1]), $ranges));
    }

    /** Öre to whole kronor, half away from zero. */
    public static function kronor(int $ore): int
    {
        return (int) round($ore / 100, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * @param  array<int, int|string>  $fields
     * @return array<int, int|string>
     */
    private static function withoutZeros(array $fields): array
    {
        return array_filter($fields, fn (int|string $value): bool => $value !== 0);
    }
}
