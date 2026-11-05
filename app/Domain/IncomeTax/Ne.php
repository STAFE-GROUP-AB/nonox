<?php

declare(strict_types=1);

namespace App\Domain\IncomeTax;

use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\Company\ReportingFramework;
use App\Domain\Vat\VatBoxes;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\IncomeTaxReturn;
use Illuminate\Validation\ValidationException;

/**
 * The NE-bilaga of a sole trader (SKV 2161) from the books, with the tax
 * adjustments the owner enters and the ones NONOX calculates from what the
 * owner confirms (ADR-053): the balance sheet B1–B16 and the income
 * statement R1–R11 from the BAS accounts (NeCodes), the adjustments R13–R46,
 * räntefördelning (IL 33 kap.), periodiseringsfond (IL 30 kap. 6 §),
 * expansionsfond (IL 34 kap.) and the schablonavdrag for egenavgifter (IL 16
 * kap. 30 §), and the surplus or deficit R47/R48 for INK1. Nothing is
 * booked; the owner files the NE in Skatteverket's e-service.
 *
 * Amounts are whole kronor. A cap ("högst") is rounded down, a negative
 * räntefördelning (an income) half up.
 *
 * TODO(legal-verify): the rounding of each calculated amount; that the
 * underlag for the schablonavdrag is R42 (16 kap. 30 §: the surplus before
 * the deduction, less sjukpenning, which the form adds only in R44); that a
 * company not registered for VAT puts all its sales in R2.
 */
final class Ne
{
    /** @var ?array<int, int|string> */
    private ?array $fields = null;

    /** @var ?array<string, mixed> */
    private ?array $calculation = null;

    /** @var array<int, ?int> account number => vat_box override */
    private array $boxes;

    public function __construct(
        public readonly Company $company,
        public readonly FiscalYear $fiscalYear,
        private readonly AnnualAccounts $accounts,
        private readonly ?IncomeTaxReturn $return,
        private readonly ?IncomeTaxParameters $parameters,
    ) {
        $this->boxes = Account::query()->whereBetween('number', [3000, 3999])->pluck('vat_box', 'number')
            ->mapWithKeys(fn (mixed $box, mixed $number): array => [(int) $number => $box === null ? null : (int) $box])->all();
    }

    public static function for(Company $company, FiscalYear $fiscalYear, ?IncomeTaxReturn $return): self
    {
        return new self($company, $fiscalYear, AnnualAccounts::for($fiscalYear), $return, IncomeTaxParameters::for($fiscalYear->end_date->year));
    }

    /** The beskattningsperiod: a sole trader's year is the calendar year, so P4. */
    public function period(): string
    {
        return Periods::of($this->fiscalYear);
    }

    public function isVerifiedPeriod(): bool
    {
        return in_array($this->period(), NeCodes::VERIFIED_PERIODS, true);
    }

    /** Whether the year's parameters (statslåneränta, percentages) are in the data and verified. */
    public function hasParameters(): bool
    {
        return $this->parameters !== null;
    }

    /**
     * The owner's personnummer in SRU, twelve digits: the century the owner
     * entered and the ten digits of the company's number (a sole trader's
     * number is the owner's personnummer).
     */
    public function identity(): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $this->company->org_number);
        $century = $this->century();

        if (strlen($digits) !== 10 || $century === null) {
            throw ValidationException::withMessages(['century' => __('Ange vilket århundrade du är född på, så att personnumret blir rätt i filen.')]);
        }

        return $century.$digits;
    }

    /** The owner's year of birth, from the century entered and the personnummer. */
    public function birthYear(): ?int
    {
        $digits = (string) preg_replace('/\D/', '', (string) $this->company->org_number);
        $century = $this->century();

        return $century === null || strlen($digits) !== 10 ? null : (int) ($century.substr($digits, 0, 2));
    }

    /** @return array<int, int|string> the NE: code => whole kronor, X for the boxes, dates as YYYYMMDD */
    public function ne(): array
    {
        if ($this->fields !== null) {
            return $this->fields;
        }

        $fields = [7011 => $this->fiscalYear->start_date->format('Ymd'), 7012 => $this->fiscalYear->end_date->format('Ymd')];
        $activity = trim((string) ($this->input('activity') ?? ''));

        if ($activity !== '') {
            $fields[NeCodes::ACTIVITY] = mb_substr($activity, 0, 80);
        }

        foreach ([NeCodes::PASSIVE => 'passive', NeCodes::OUTSIDE_EEA => 'outside_eea'] as $code => $key) {
            if ($this->input($key) === true) {
                $fields[$code] = 'X';
            }
        }

        if ($this->company->reporting_framework !== ReportingFramework::K1) {
            $fields[NeCodes::NOT_SIMPLIFIED] = 'X';
        }

        $fields += $this->balanceSheet() + $this->incomeStatement();
        $fields += $this->taxAdjustments();

        if ($this->input('leased_car') === true) {
            $fields[NeCodes::LEASED_CAR] = 'X';
        }

        foreach (array_keys(NeCodes::INFORMATION) as $code) {
            $fields[$code] = $this->entered($code);
        }

        $fields += $this->otherInformation();

        if ($this->return?->consultant !== null) {
            $fields[$this->return->consultant ? NeCodes::CONSULTANT_YES : NeCodes::CONSULTANT_NO] = 'X';
        }

        return $this->fields = array_filter($fields, fn (int|string $value): bool => $value !== 0);
    }

    /** @return array<int, int> B1–B16 */
    public function balanceSheet(): array
    {
        $fields = [];
        $assets = 0;
        $liabilities = 0;

        foreach (NeCodes::ASSETS as $code => $ranges) {
            $fields[$code] = Ink2::kronor($this->closing($ranges));
            $assets += $fields[$code];
        }

        // B11–B12 are not filled in under förenklat årsbokslut (the form); they stay in B16 then.
        $simplified = $this->company->reporting_framework === ReportingFramework::K1;

        foreach (NeCodes::LIABILITIES as $code => $ranges) {
            $amount = Ink2::kronor(-$this->closing($ranges));

            if ($simplified && in_array($code, [7320, 7330], true)) {
                $fields[7383] = ($fields[7383] ?? 0) + $amount;

                continue;
            }

            $fields[$code] = ($fields[$code] ?? 0) + $amount;
        }

        foreach (array_keys(NeCodes::LIABILITIES) as $code) {
            $liabilities += $fields[$code] ?? 0;
        }

        $fields[NeCodes::EQUITY] = $assets - $liabilities;

        return $fields;
    }

    /** @return array<int, int> R1–R11 */
    public function incomeStatement(): array
    {
        $fields = array_fill_keys([7400, 7401, 7402, 7403, 7500, 7501, 7502, 7503, 7504, 7505], 0);
        $exemptCompany = ! $this->company->isVatRegistered();

        foreach ($this->accounts->numbers() as $number) {
            $movement = $this->accounts->movementOf($number, $number);

            if ($movement === 0) {
                continue;
            }

            if (self::inRanges($number, NeCodes::SALES)) {
                $exempt = $exemptCompany || VatBoxes::boxFor($number, $this->boxes[$number] ?? null) === VatBoxes::EXEMPT_SALES;
                $fields[$exempt ? NeCodes::SALES_EXEMPT : NeCodes::SALES_TAXABLE] -= $movement;

                continue;
            }

            if (self::inRanges($number, NeCodes::FINANCIAL)) {
                // Counted by the account's own sign (BAS "(+)"): income to R4, cost to R8.
                $movement < 0 ? $fields[NeCodes::INTEREST_INCOME] -= $movement : $fields[NeCodes::INTEREST_COSTS] += $movement;

                continue;
            }

            foreach (NeCodes::RESULT as $code => $ranges) {
                if (self::inRanges($number, $ranges)) {
                    $fields[$code] += $code === NeCodes::INTEREST_INCOME ? -$movement : $movement;

                    break;
                }
            }
        }

        $fields = array_map(fn (int $ore): int => Ink2::kronor($ore), $fields);
        $fields[NeCodes::BENEFITS] = $this->entered(NeCodes::BENEFITS);
        $fields[NeCodes::RESULT_BOOKED] = $fields[7400] + $fields[7401] + $fields[7402] + $fields[7403]
            - $fields[7500] - $fields[7501] - $fields[7502] - $fields[7503] - $fields[7504] - $fields[7505];

        return $fields;
    }

    /** The book result in whole kronor (R11). */
    public function resultKronor(): int
    {
        return $this->incomeStatement()[NeCodes::RESULT_BOOKED];
    }

    /**
     * R12–R48: the entered adjustments and the calculated ones, in the form's
     * order, with the subtotals behind them.
     *
     * @return array<int, int>
     */
    public function taxAdjustments(): array
    {
        $calc = $this->calculation();
        $fields = [NeCodes::TAX_RESULT_START => $calc['r12']];

        foreach (array_keys(NeCodes::ADJUSTMENTS) as $code) {
            $fields[$code] = $this->entered($code);
        }

        $fields[NeCodes::POSITIVE_ALLOCATION] = $calc['r30'];
        $fields[NeCodes::NEGATIVE_ALLOCATION] = $calc['r31'];
        $fields[NeCodes::RESERVE_ALLOCATION] = $calc['r34'];
        $fields[NeCodes::EXPANSION_INCREASE] = $calc['r36'];
        $fields[NeCodes::EXPANSION_DECREASE] = $calc['r37'];
        $fields[NeCodes::SELF_EMPLOYMENT_DEDUCTION] = $calc['r43'] ?? 0;
        $fields[$calc['result'] >= 0 ? NeCodes::SURPLUS : NeCodes::DEFICIT] = abs($calc['result']);

        return $fields;
    }

    /**
     * The calculation behind R12–R48, with the limits the owner chooses within.
     *
     * @return array{r12: int, r29: int, positive_max: int, r30: int, r31: int, saved: int, r33: int, r34_max: int, r34: int, r35: int, r36_max: ?int, r36: int, r37: int, r42: int, r43_rate: ?int, r43: ?int, result: int}
     */
    public function calculation(): array
    {
        if ($this->calculation !== null) {
            /** @var array{r12: int, r29: int, positive_max: int, r30: int, r31: int, saved: int, r33: int, r34_max: int, r34: int, r35: int, r36_max: ?int, r36: int, r37: int, r42: int, r43_rate: ?int, r43: ?int, result: int} */
            return $this->calculation;
        }

        $r12 = $this->resultKronor();
        $r29 = $r12 + $this->signed(NeCodes::BEFORE_INTEREST);
        $months = TaxAllocationReserves::months($this->fiscalYear);
        $base = $this->inputInt('capital_base');
        $positiveMax = 0;
        $r31 = 0;

        if ($this->parameters !== null && $base !== null) {
            if ($base > 0) {
                $positiveMax = intdiv($base * $this->parameters->positiveAllocationRate() * $months, 10_000 * 12);
            }

            if ($base * 100 < $this->parameters->int('interest_allocation_negative_threshold_ore')) {
                $r31 = self::halfUp(-$base * $this->parameters->negativeAllocationRate() * $months, 10_000 * 12);
            }
        }

        // IL 33 kap. 5 §: at most the result before räntefördelning; the owner may use less and save the rest.
        $usable = min($positiveMax, max($r29, 0));
        $chosen = $this->inputInt('positive_allocation');
        $r30 = $chosen === null ? $usable : max(min($chosen, $usable), 0);
        $saved = $positiveMax - $r30;
        $r33 = $r29 - $r30 + $r31 + $this->entered(NeCodes::RESERVE_RETURN);

        // IL 30 kap. 6 §: at most 30 % of the result (the form: of R33).
        $r34Max = $this->parameters === null ? 0 : intdiv(max($r33, 0) * $this->parameters->int('tax_allocation_reserve_share_bp'), 10_000);
        $r34 = min($this->entered(NeCodes::RESERVE_ALLOCATION), $r34Max);
        $r35 = $r33 - $r34;

        // IL 34 kap. 5–6 §§: at most the result, and the fund at most 125,94 % of the kapitalunderlag.
        $r36Max = max($r35, 0);
        $expansionBase = $this->inputInt('expansion_capital_base');

        if ($this->parameters !== null && $expansionBase !== null) {
            $room = intdiv(max($expansionBase, 0) * $this->parameters->int('expansion_fund_cap_bp'), 10_000) - ($this->inputInt('expansion_fund_opening') ?? 0);
            $r36Max = min($r36Max, max($room, 0));
        }

        $r36 = min($this->entered(NeCodes::EXPANSION_INCREASE), $r36Max);
        $r37 = $this->entered(NeCodes::EXPANSION_DECREASE);
        $r42 = $r35 - $r36 + $r37 + $this->signed(NeCodes::BEFORE_SELF_EMPLOYMENT);

        $rate = $this->selfEmploymentRate();
        $override = $this->return?->adjustments[NeCodes::SELF_EMPLOYMENT_DEDUCTION] ?? null;
        $r43 = $override !== null ? (int) $override : ($rate === null ? null : intdiv(max($r42, 0) * $rate, 10_000));

        return $this->calculation = [
            'r12' => $r12,
            'r29' => $r29,
            'positive_max' => $positiveMax,
            'r30' => $r30,
            'r31' => $r31,
            'saved' => max($saved, 0),
            'r33' => $r33,
            'r34_max' => $r34Max,
            'r34' => $r34,
            'r35' => $r35,
            'r36_max' => $r36Max,
            'r36' => $r36,
            'r37' => $r37,
            'r42' => $r42,
            'r43_rate' => $rate,
            'r43' => $r43,
            'result' => $r42 - ($r43 ?? 0) + $this->signed(NeCodes::AFTER_SELF_EMPLOYMENT),
        ];
    }

    /**
     * The schablon for egenavgifter in basis points: 20 % for passive
     * business, 25 % for an owner born 1959 or later, 10 % for one born
     * 1938–1958; null when the year of birth or the year's parameters are
     * missing, or for an owner born 1937 or earlier.
     *
     * TODO(legal-verify): an owner born 1937 or earlier, and one with a full
     * old-age pension before 66.
     */
    public function selfEmploymentRate(): ?int
    {
        if ($this->parameters === null) {
            return null;
        }

        $rates = $this->parameters->map('self_employment_deduction_bp');

        if ($this->input('passive') === true) {
            return $rates['passive'];
        }

        $born = $this->birthYear();

        return match (true) {
            $born === null => null,
            $born >= 1959 => $rates['born_from_1959'],
            $born >= 1938 => $rates['born_1938_to_1958'],
            default => null,
        };
    }

    /**
     * Things to check before filing, in Swedish.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        if ($this->parameters === null) {
            $warnings[] = __('Statslåneräntan och procentsatserna för inkomståret :year finns inte i NONOX än. Räntefördelning, periodiseringsfond och schablonavdraget för egenavgifter räknas inte ut förrän de är kontrollerade.', ['year' => $this->fiscalYear->end_date->year]);
        } elseif ($this->selfEmploymentRate() === null && ($this->return?->adjustments[NeCodes::SELF_EMPLOYMENT_DEDUCTION] ?? null) === null) {
            $warnings[] = $this->century() === null
                ? __('Ange vilket århundrade du är född på, så räknas schablonavdraget för egenavgifter (R43) ut.')
                : __('NONOX räknar inte ut schablonavdraget för egenavgifter för den som är född 1937 eller tidigare. Fyll i R43 själv.');
        }

        if (($this->balanceSheet()[NeCodes::TAX_DEBTS] ?? 0) !== 0) {
            $warnings[] = __('Det finns ett saldo på skatteskulder (2500–2599). Ägarens egna skatter är privata och ska inte bokföras i firman; kontrollera beloppet i B14.');
        }

        $calc = $this->calculation();

        if ($this->entered(NeCodes::RESERVE_ALLOCATION) > $calc['r34_max']) {
            $warnings[] = __('Avsättningen till periodiseringsfond är större än 30 % av överskottet i R33 och har satts till :amount kr.', ['amount' => $calc['r34_max']]);
        }

        if ($calc['r36_max'] !== null && $this->entered(NeCodes::EXPANSION_INCREASE) > $calc['r36_max']) {
            $warnings[] = __('Ökningen av expansionsfonden är större än det som får sättas av och har satts till :amount kr.', ['amount' => $calc['r36_max']]);
        }

        return $warnings;
    }

    /** @return array<int, int> 8009–8012 when they are needed (the form's footnotes) */
    private function otherInformation(): array
    {
        $calc = $this->calculation();
        $base = $this->inputInt('capital_base');
        $fields = [];

        if ($base !== null && ($calc['r30'] > 0 || $calc['r31'] > 0 || $calc['saved'] > 0)) {
            $fields[$base >= 0 ? NeCodes::CAPITAL_BASE_POSITIVE : NeCodes::CAPITAL_BASE_NEGATIVE] = abs($base);
        }

        if ($calc['saved'] > 0) {
            $fields[NeCodes::SAVED_ALLOCATION] = $calc['saved'];
        }

        $fund = ($this->inputInt('expansion_fund_opening') ?? 0) + $calc['r36'] - $calc['r37'];
        $expansionBase = $this->inputInt('expansion_capital_base');

        if ($fund > 0 && $expansionBase !== null && $expansionBase > 0) {
            $fields[NeCodes::EXPANSION_CAPITAL_BASE] = $expansionBase;
        }

        return $fields;
    }

    /** @param  list<int>  $codes */
    private function signed(array $codes): int
    {
        $sum = 0;

        foreach ($codes as $code) {
            $sum += NeCodes::ADJUSTMENTS[$code][1] * $this->entered($code);
        }

        return $sum;
    }

    /** An entered amount in whole kronor, 0 when nothing is entered. */
    public function entered(int $code): int
    {
        return (int) ($this->return->adjustments[$code] ?? 0);
    }

    private function input(string $key): mixed
    {
        return $this->return->inputs[$key] ?? null;
    }

    private function inputInt(string $key): ?int
    {
        $value = $this->input($key);

        return is_int($value) ? $value : null;
    }

    private function century(): ?string
    {
        $century = $this->return->personal['century'] ?? null;

        return in_array($century, ['19', '20'], true) ? $century : null;
    }

    /** @param  list<array{0: int, 1: int}>  $ranges */
    private function closing(array $ranges): int
    {
        return array_sum(array_map(fn (array $range): int => $this->accounts->closingOf($range[0], $range[1]), $ranges));
    }

    /** @param  list<array{0: int, 1: int}>  $ranges */
    private static function inRanges(int $number, array $ranges): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($number >= $from && $number <= $to) {
                return true;
            }
        }

        return false;
    }

    private static function halfUp(int $numerator, int $denominator): int
    {
        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
