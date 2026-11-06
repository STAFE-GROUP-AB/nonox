<?php

declare(strict_types=1);

namespace App\Domain\IncomeTax;

use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\Closing\CorporateTaxRules;
use App\Domain\Closing\TaxProposal;
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
 * The schablonintäkt on periodiseringsfonder (4.6 a, field 7654) is
 * proposed from the opening balances of BAS 2110–2149 (see
 * TaxAllocationReserves). The basis for särskild löneskatt on pension
 * costs (INK2 1.4/1.5, fields 7132/7133) and the pension costs in INK2S 4.21
 * (field 8022) are proposed from BAS 74xx. An entered amount replaces a
 * proposal.
 *
 * TODO(legal-verify): only the adjustments listed in Ink2Codes::ADJUSTMENTS
 * are supported; delägarrätter, andelar i handelsbolag and the other INK2S
 * points must be added by hand in Skatteverket's service.
 */
final class Ink2
{
    /** @var ?array<int, ?int> */
    private ?array $proposals = null;

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
        return Periods::of($this->fiscalYear);
    }

    public function isVerifiedPeriod(): bool
    {
        return in_array($this->period(), Ink2Codes::VERIFIED_PERIODS, true);
    }

    /**
     * The identity of a company in SRU: 16 and the ten digits of the
     * organisation number (Teknisk information om filöverföring: legal
     * persons 16, natural persons 19 or 20, twelve digits).
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
            $amount = -$this->accounts->movementOfRanges($line['ranges'], $line['resale'] ?? null);
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
        $fields[Ink2Codes::S_PENSION_COSTS] = $this->valueOf(Ink2Codes::S_PENSION_COSTS);
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

        return self::withoutZeros([
            ...$this->dates(),
            $taxable >= 0 ? Ink2Codes::SURPLUS : Ink2Codes::DEFICIT => abs($taxable),
            Ink2Codes::PENSION_BASIS => $this->valueOf(Ink2Codes::PENSION_BASIS),
            Ink2Codes::PENSION_BASIS_NEGATIVE => $this->valueOf(Ink2Codes::PENSION_BASIS_NEGATIVE),
        ]);
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

    /** @return array<int, int> code => whole kronor, entered or proposed, without zeros */
    public function adjustments(): array
    {
        $adjustments = [];

        foreach (array_keys(Ink2Codes::ADJUSTMENTS) as $code) {
            $amount = $this->valueOf($code);

            if ($amount !== 0) {
                $adjustments[$code] = $amount;
            }
        }

        return $adjustments;
    }

    /**
     * What the books propose for the fields in Ink2Codes::PROPOSED, in whole
     * kronor; null when the proposal cannot be made (a rate is missing).
     *
     * @return array<int, ?int>
     */
    public function proposals(): array
    {
        if ($this->proposals !== null) {
            return $this->proposals;
        }

        $standardIncome = TaxAllocationReserves::standardIncomeOre($this->fiscalYear, $this->accounts);
        // TODO(legal-verify): the basis under lag (1991:687) 2 § is not always
        // the booked pension cost (e.g. tryggandelagen provisions, item j,
        // administration fees); the booked net of BAS 74xx is only a proposal.
        $pension = self::kronor($this->accounts->movementOf(Ink2Codes::PENSION_ACCOUNTS[0], Ink2Codes::PENSION_ACCOUNTS[1]));

        // TODO(legal-verify): that 6072, 6982, 6992 and 8423 hold only costs that may not be deducted, and 8314 only income that is not taxable (ADR-056).
        $marked = [];

        foreach (CorporateTaxRules::adjustments() as $code => $adjustment) {
            $marked[$code] = array_sum(array_map(fn (int $number): int => $this->accounts->movementOf($number, $number), $adjustment['accounts']));
        }

        return $this->proposals = [
            Ink2Codes::S_STANDARD_INCOME => $standardIncome === null ? null : self::kronor($standardIncome),
            Ink2Codes::PENSION_BASIS => max($pension, 0),
            Ink2Codes::PENSION_BASIS_NEGATIVE => max(-$pension, 0),
            Ink2Codes::S_PENSION_COSTS => max($pension, 0),
            Ink2Codes::S_NON_DEDUCTIBLE => max(self::kronor($marked[Ink2Codes::S_NON_DEDUCTIBLE] ?? 0), 0),
            Ink2Codes::S_NON_TAXABLE => max(self::kronor(-($marked[Ink2Codes::S_NON_TAXABLE] ?? 0)), 0),
        ];
    }

    /**
     * The tax on the taxable surplus as the books stand, in öre: the surplus
     * rounded down to whole tens of kronor (IL 1 kap. 7 §) at the rate for
     * the year (IL 65 kap. 10 §, CorporateTaxRules). Null when the rate for
     * the year is not in the data. Without a new periodiseringsfond; the
     * closing step (TaxProposal) adds that.
     */
    public function proposedTaxOre(): ?int
    {
        $rate = CorporateTaxRules::rateFor($this->fiscalYear);

        return $rate === null ? null : TaxProposal::taxOre(TaxProposal::roundedTaxable($this->taxableKronor()), $rate);
    }

    /** Whether a person has entered the field, 0 included. */
    public function isEntered(int $code): bool
    {
        return array_key_exists($code, $this->return->adjustments ?? []);
    }

    /**
     * Things the person must check before filing, in Swedish.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        if ($this->proposals()[Ink2Codes::S_STANDARD_INCOME] === null && ! $this->isEntered(Ink2Codes::S_STANDARD_INCOME)) {
            $warnings[] = __('Företaget har periodiseringsfonder vid årets början, men statslåneräntan för året finns inte här. Räkna fram schablonintäkten (fält 7654) och fyll i den.');
        }

        $basis = $this->valueOf(Ink2Codes::PENSION_BASIS) + $this->valueOf(Ink2Codes::PENSION_BASIS_NEGATIVE);

        if ($this->valueOf(Ink2Codes::S_PENSION_COSTS) > 0 && $basis === 0) {
            $warnings[] = __('Pensionskostnader redovisas i punkt 4.21, men underlaget för särskild löneskatt (punkt 1.4 eller 1.5) är tomt. Kontrollera underlaget.');
        }

        // A field with only one side on the form (e.g. 3.5 Råvaror och förnödenheter) may not be negative.
        $fields = $this->ink2r();

        foreach (Ink2Codes::INCOME as $line) {
            $code = $line['income'] ?? $line['cost'];

            if (($line['income'] === null || $line['cost'] === null) && is_int($fields[$code] ?? null) && $fields[$code] < 0) {
                $warnings[] = __('Fält :code (:label) blir negativt, :amount kr. Det fältet har bara en sida på blanketten. Kontrollera kontona, till exempel om en lagerförändring ska ligga på ett annat konto.', ['code' => $code, 'label' => __(Ink2Codes::LABELS[$code]), 'amount' => $fields[$code]]);
            }
        }

        return $warnings;
    }

    /** The entered amount, else the proposal, else 0. */
    private function valueOf(int $code): int
    {
        $entered = $this->return->adjustments ?? [];

        if (array_key_exists($code, $entered)) {
            return (int) $entered[$code];
        }

        // INK2 1.4 and 1.5 go together: once one is entered, the other is not proposed.
        $pair = [Ink2Codes::PENSION_BASIS => Ink2Codes::PENSION_BASIS_NEGATIVE, Ink2Codes::PENSION_BASIS_NEGATIVE => Ink2Codes::PENSION_BASIS];

        if (isset($pair[$code]) && array_key_exists($pair[$code], $entered)) {
            return 0;
        }

        return in_array($code, Ink2Codes::PROPOSED, true) ? ($this->proposals()[$code] ?? 0) : 0;
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
