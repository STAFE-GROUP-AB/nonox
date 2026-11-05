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
 * Positive räntefördelning is never taken by default: the owner chooses
 * how much (IL 33 kap. 6 §, "får helt eller delvis avstå"), and what is not
 * used is saved with last year's saved amount (33 kap. 7 §). There is no
 * 50 000 kr floor any more (removed by 2024:1131 for years starting after
 * 2024-12-31; Skatteverket's brochure SKV 306 utg. 17 still mentions it).
 *
 * TODO(legal-verify): the rounding of each calculated amount; that a saved
 * fördelningsbelopp left over after a negative räntefördelning may be used
 * for positive räntefördelning (only the correction notice on SKV 2196 says
 * so); the schablon for a passive owner born 1937 or earlier.
 */
final class Ne
{
    /** ML 18 kap. 4 §: the årsomsättning up to which a business is exempt from VAT. */
    private const int VAT_EXEMPTION_LIMIT = 120_000;

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
        }

        // An overdrawn bank account is a loan (B13), not negative cash; a net
        // VAT receivable on 26xx is a receivable (B8), not a negative debt.
        $overdrawn = 0;

        foreach ($this->accounts->numbers() as $number) {
            $closing = $number >= NeCodes::CASH[0] && $number <= NeCodes::CASH[1] ? $this->accounts->closingOf($number, $number) : 0;
            $overdrawn += min($closing, 0);
        }

        $fields[NeCodes::CASH_FIELD] = Ink2::kronor($this->closing([NeCodes::CASH]) - $overdrawn);
        $vat = $this->closing([NeCodes::VAT]);
        $fields[NeCodes::OTHER_RECEIVABLES] += $vat > 0 ? Ink2::kronor($vat) : 0;

        foreach (array_keys(NeCodes::ASSETS) as $code) {
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

        $fields[NeCodes::LOANS] += Ink2::kronor(-$overdrawn);
        $fields[NeCodes::OTHER_LIABILITIES] += $vat < 0 ? Ink2::kronor(-$vat) : 0;

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
        $benefits = $this->benefitAccounts();

        foreach ($this->accounts->numbers() as $number) {
            $movement = $this->accounts->movementOf($number, $number);

            if ($movement === 0) {
                continue;
            }

            if (in_array($number, $benefits, true)) {
                $fields[NeCodes::BENEFITS] -= $movement;

                continue;
            }

            if (self::inRanges($number, NeCodes::SALES)) {
                $exempt = $exemptCompany || VatBoxes::boxFor($number, $this->boxes[$number] ?? null) === VatBoxes::EXEMPT_SALES;
                $fields[$exempt ? NeCodes::SALES_EXEMPT : NeCodes::SALES_TAXABLE] -= $movement;

                continue;
            }

            if (self::inRanges($number, NeCodes::FINANCIAL_INCOME)) {
                $fields[NeCodes::INTEREST_INCOME] -= $movement;

                continue;
            }

            if (self::inRanges($number, NeCodes::FINANCIAL_COSTS)) {
                $fields[NeCodes::INTEREST_COSTS] += $movement;

                continue;
            }

            if (self::inRanges($number, NeCodes::FINANCIAL)) {
                // The rest is counted by the account's own sign (BAS "(+)"): income to R4, cost to R8.
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
     * Räntefördelning (IL 33 kap.): the positive fördelningsbelopp from the
     * kapitalunderlag (none the first year, Skatteverket's ställningstagande
     * 2026-01-26, dnr 8-16818-2026) plus last year's saved amount (7 §), and
     * a negative one below minus 500 000 kr (4 §) that the saved amount is
     * set off against first (7 §). R30 is what the owner chooses, at most the
     * result before räntefördelning (5 §); nothing is chosen for the owner
     * (6 §). The rest is saved (8011).
     *
     * Expansionsfond (IL 34 kap.): the fund at the end of the year at most
     * 125,94 % of the kapitalunderlag (6–7 §§); a fund above the cap is
     * reversed (15 §), and without a positive underlag the whole fund is.
     *
     * @return array{r12: int, r29: int, from_base: int, saved_opening: int, negative: int, positive_max: int, usable: int, chosen: ?int, r30: int, r31: int, saved: int, r33: int, r34_max: int, r34: int, r35: int, r36_max: int, r36: int, r37: int, r37_forced: int, expansion_cap: ?int, r42: int, r43_rate: ?int, r43: ?int, result: int}
     */
    public function calculation(): array
    {
        if ($this->calculation !== null) {
            /** @var array{r12: int, r29: int, from_base: int, saved_opening: int, negative: int, positive_max: int, usable: int, chosen: ?int, r30: int, r31: int, saved: int, r33: int, r34_max: int, r34: int, r35: int, r36_max: int, r36: int, r37: int, r37_forced: int, expansion_cap: ?int, r42: int, r43_rate: ?int, r43: ?int, result: int} */
            return $this->calculation;
        }

        $r12 = $this->resultKronor();
        $r29 = $r12 + $this->signed(NeCodes::BEFORE_INTEREST);
        $months = TaxAllocationReserves::months($this->fiscalYear);
        $base = $this->inputInt('capital_base');
        $savedOpening = max($this->inputInt('saved_allocation_opening') ?? 0, 0);
        $fromBase = 0;
        $negative = 0;

        if ($this->parameters !== null && $base !== null) {
            if ($base > 0 && $this->input('first_year') !== true) {
                $fromBase = intdiv($base * $this->parameters->positiveAllocationRate() * $months, 10_000 * 12);
            }

            if ($base * 100 < $this->parameters->int('interest_allocation_negative_threshold_ore')) {
                $negative = self::halfUp(-$base * $this->parameters->negativeAllocationRate() * $months, 10_000 * 12);
            }
        }

        // 33 kap. 7 §: the saved amount is set off against a negative fördelningsbelopp first.
        $r31 = max($negative - $savedOpening, 0);
        $positiveMax = $fromBase + max($savedOpening - $negative, 0);

        // 33 kap. 5–6 §§: at most the result before räntefördelning, and only what the owner chooses.
        $usable = min($positiveMax, max($r29, 0));
        $chosen = $this->inputInt('positive_allocation');
        $r30 = $chosen === null ? 0 : max(min($chosen, $usable), 0);
        $saved = $positiveMax - $r30;
        $r33 = $r29 - $r30 + $r31 + $this->entered(NeCodes::RESERVE_RETURN);

        // IL 30 kap. 6 §: at most 30 % of the result (the form: of R33).
        $r34Max = $this->parameters === null ? 0 : intdiv(max($r33, 0) * $this->parameters->int('tax_allocation_reserve_share_bp'), 10_000);
        $r34 = min($this->entered(NeCodes::RESERVE_ALLOCATION), $r34Max);
        $r35 = $r33 - $r34;

        $opening = max($this->inputInt('expansion_fund_opening') ?? 0, 0);
        $r37 = min($this->entered(NeCodes::EXPANSION_DECREASE), $opening);
        $r36Max = max($r35, 0);
        $cap = null;
        $forced = 0;

        if ($this->parameters !== null) {
            $expansionBase = $this->inputInt('expansion_capital_base');
            $cap = $expansionBase === null || $expansionBase <= 0 ? 0 : intdiv($expansionBase * $this->parameters->int('expansion_fund_cap_bp'), 10_000);
            $r36Max = min($r36Max, max($cap - $opening + $r37, 0));
            $forced = max($opening - $r37 - $cap, 0);
        }

        $r36 = min($this->entered(NeCodes::EXPANSION_INCREASE), $r36Max);
        $r42 = $r35 - $r36 + $r37 + $this->signed(NeCodes::BEFORE_SELF_EMPLOYMENT);

        $rate = $this->selfEmploymentRate();
        $override = $this->return?->adjustments[NeCodes::SELF_EMPLOYMENT_DEDUCTION] ?? null;
        $r43 = $override !== null ? (int) $override : ($rate === null ? null : intdiv(max($r42, 0) * $rate, 10_000));

        return $this->calculation = [
            'r12' => $r12,
            'r29' => $r29,
            'from_base' => $fromBase,
            'saved_opening' => $savedOpening,
            'negative' => $negative,
            'positive_max' => $positiveMax,
            'usable' => $usable,
            'chosen' => $chosen,
            'r30' => $r30,
            'r31' => $r31,
            'saved' => $saved,
            'r33' => $r33,
            'r34_max' => $r34Max,
            'r34' => $r34,
            'r35' => $r35,
            'r36_max' => $r36Max,
            'r36' => $r36,
            'r37' => $r37,
            'r37_forced' => $forced,
            'expansion_cap' => $cap,
            'r42' => $r42,
            'r43_rate' => $rate,
            'r43' => $r43,
            'result' => $r42 - ($r43 ?? 0) + $this->signed(NeCodes::AFTER_SELF_EMPLOYMENT),
        ];
    }

    /**
     * The schablon for egenavgifter in basis points (IL 16 kap. 30 §): 20 %
     * for passive business, 25 % for an active owner, 10 % for one who had
     * turned 67 when the year began (SAL 3 kap. 15 §) or had a full pension
     * or sjuk- or aktivitetsersättning (3 kap. 16 §), and 0 for one born 1937
     * or earlier, who pays no egenavgifter (SAL, övergångsbestämmelse 6).
     * Null when the year of birth or the year's parameters are missing.
     *
     * TODO(legal-verify): a passive owner born 1937 or earlier (lag 1990:659,
     * övergångsbestämmelse 5); null, so the owner fills in R43.
     */
    public function selfEmploymentRate(): ?int
    {
        if ($this->parameters === null) {
            return null;
        }

        $rates = $this->parameters->map('self_employment_deduction_bp');
        $born = $this->birthYear();
        $bornUntil = $this->parameters->int('self_employment_none_born_until');

        if ($this->input('passive') === true) {
            return $born !== null && $born <= $bornUntil ? null : $rates['passive'];
        }

        if ($born === null) {
            return null;
        }

        if ($born <= $bornUntil) {
            return 0;
        }

        // The age at the start of the year by year of birth, as Skatteverket's table (born 1958 or earlier for 2026).
        $age = $this->fiscalYear->start_date->year - $born - 1;

        if ($age >= $this->parameters->int('self_employment_reduced_from_age')) {
            return $rates['reduced'];
        }

        if ($this->input('full_pension') === true && $this->parameters->int('self_employment_pension_reduced') === 1) {
            return $rates['reduced'];
        }

        return $rates['full'];
    }

    /**
     * Proposals from the books (S6): an avsättning to periodiseringsfond
     * booked on 8810–8819 already lowers R11, while the deduction is made in
     * R34, so the booked amount goes back in R13; a booked återföring is
     * taken out in R14 and entered in R32 instead.
     *
     * @return array<int, int>
     */
    public function proposals(): array
    {
        $booked = Ink2::kronor($this->accounts->movementOf(NeCodes::RESERVE_ACCOUNTS[0], NeCodes::RESERVE_ACCOUNTS[1]));

        return match (true) {
            $booked > 0 => [7601 => $booked],
            $booked < 0 => [7700 => -$booked],
            default => [],
        };
    }

    /**
     * An estimate of the kapitalunderlag for räntefördelning from the books
     * at the end of last year (IL 33 kap. 8–11 §§): assets less liabilities,
     * without tax receivables and tax debts (1630, 25xx), periodiseringsfonder
     * as debt, less 79,4 % of the expansionsfond (2050). Only an estimate:
     * the law uses tax values (12 §), and the underlag adds last year's
     * deficit and saved amount. Null without opening balances.
     */
    public function estimatedCapitalBase(): ?int
    {
        $assets = $this->accounts->openingOf(1000, 1999) - $this->accounts->openingOf(1630, 1630);
        $liabilities = -($this->accounts->openingOf(2100, 2999) - $this->accounts->openingOf(2500, 2599));
        $fund = -$this->accounts->openingOf(2050, 2059);

        if ($assets === 0 && $liabilities === 0 && $fund === 0) {
            return null;
        }

        return Ink2::kronor($assets - $liabilities - intdiv(max($fund, 0) * 794, 1000));
    }

    /**
     * Last year's saved fördelningsbelopp (8011 on last year's NE), to start
     * this year's from.
     */
    public static function savedFromPreviousYear(Company $company, FiscalYear $fiscalYear): ?int
    {
        $previous = FiscalYear::query()->whereDate('end_date', $fiscalYear->start_date->subDay()->toDateString())->first();
        $return = $previous === null ? null : IncomeTaxReturn::query()->where('fiscal_year_id', $previous->id)->where('form', IncomeTaxReturn::NE)->first();

        return $return === null ? null : self::for($company, $previous, $return)->calculation()['saved'];
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
                : __('NONOX räknar inte ut schablonavdraget för egenavgifter vid passiv näringsverksamhet för den som är född 1937 eller tidigare. Fyll i R43 själv.');
        }

        if (($this->balanceSheet()[NeCodes::TAX_DEBTS] ?? 0) !== 0) {
            $warnings[] = __('Det finns ett saldo på skatteskulder (2500–2599). Ägarens egna skatter är privata och ska inte bokföras i firman; kontrollera beloppet i B14.');
        }

        foreach ($this->balanceSheet() as $code => $amount) {
            if ($amount < 0 && $code !== NeCodes::EQUITY) {
                $warnings[] = __(':label blir negativt, :amount. Kontrollera kontona bakom raden.', ['label' => __(NeCodes::LABELS[$code]), 'amount' => IncomeTaxForms::kronor($amount)]);
            }
        }

        $warnings = [...$warnings, ...$this->allocationWarnings()];
        $calc = $this->calculation();

        if ($this->entered(NeCodes::RESERVE_ALLOCATION) > $calc['r34_max']) {
            $warnings[] = __('Avsättningen till periodiseringsfond är större än 30 % av överskottet i R33 och har satts till :amount.', ['amount' => IncomeTaxForms::kronor($calc['r34_max'])]);
        }

        foreach ($this->proposals() as $code => $amount) {
            $warnings[] = $code === 7601
                ? __('En avsättning till periodiseringsfond är bokförd på 8810–8819 (:amount). Avdraget görs i R34, så lägg det bokförda beloppet i R13 och fyll i avsättningen i R34; annars dras den av två gånger.', ['amount' => IncomeTaxForms::kronor($amount)])
                : __('En återföring av periodiseringsfond är bokförd på 8810–8819 (:amount). Den tas upp i R32, så lägg det bokförda beloppet i R14 och fyll i återföringen i R32.', ['amount' => IncomeTaxForms::kronor($amount)]);
        }

        if ($this->accounts->openingOf(NeCodes::RESERVES[0], NeCodes::RESERVES[1]) !== 0) {
            $warnings[] = __('Det finns periodiseringsfonder (2110–2149). En fond ska återföras senast det sjätte beskattningsåret efter avsättningen, den äldsta först (inkomstskattelagen 30 kap. 7 §). Fyll i återföringen i R32 det år den äldsta fonden ska återföras.');
        }

        if ($this->entered(NeCodes::EXPANSION_INCREASE) > $calc['r36_max']) {
            $warnings[] = __('Ökningen av expansionsfonden är större än det som får sättas av och har satts till :amount.', ['amount' => IncomeTaxForms::kronor($calc['r36_max'])]);
        }

        if ($calc['r37_forced'] > 0) {
            $warnings[] = $calc['expansion_cap'] === 0
                ? __('Det finns inget positivt kapitalunderlag för expansionsfond vid årets slut, så expansionsfonden ska återföras: :amount i R37 (inkomstskattelagen 34 kap. 15 §).', ['amount' => IncomeTaxForms::kronor($calc['r37_forced'] + $calc['r37'])])
                : __('Expansionsfonden blir större än 125,94 % av kapitalunderlaget. Minst :amount ska återföras i R37 (inkomstskattelagen 34 kap. 6 och 15 §§).', ['amount' => IncomeTaxForms::kronor($calc['r37_forced'] + $calc['r37'])]);
        }

        $sales = $this->incomeStatement()[NeCodes::SALES_TAXABLE] + $this->incomeStatement()[NeCodes::SALES_EXEMPT];

        // ML 18 kap. 4 och 11 §§; the two years before are not checked here.
        if (! $this->company->isVatRegistered() && $sales > self::VAT_EXEMPTION_LIMIT) {
            $warnings[] = __('Företaget är inte momsregistrerat, men försäljningen är :amount. Momsbefrielsen gäller bara upp till 120 000 kr om året och upphör när försäljningen går över gränsen (mervärdesskattelagen 18 kap. 4 och 11 §§). Kontrollera om företaget ska registreras för moms.', ['amount' => IncomeTaxForms::kronor($sales)]);
        }

        return $warnings;
    }

    /** @return list<string> räntefördelning: the choice, the first year, a mandatory negative one */
    private function allocationWarnings(): array
    {
        if ($this->parameters === null) {
            return [];
        }

        $warnings = [];
        $calc = $this->calculation();
        $base = $this->inputInt('capital_base');

        if ($this->input('first_year') === true) {
            $warnings[] = __('Det första året med näringsverksamheten finns inget kapitalunderlag från förra årets slut, så ingen positiv räntefördelning räknas fram ur kapitalunderlaget (Skatteverkets ställningstagande 2026-01-26, dnr 8-16818-2026).');
        }

        if ($calc['usable'] > 0 && $calc['chosen'] === null) {
            $warnings[] = __('Välj hur mycket positiv räntefördelning du vill göra, högst :max. Tills du har valt görs ingen, och beloppet sparas till nästa år.', ['max' => IncomeTaxForms::kronor($calc['usable'])]);
        }

        if ($base === null) {
            $estimate = $this->estimatedCapitalBase();

            if ($estimate !== null && $estimate * 100 < $this->parameters->int('interest_allocation_negative_threshold_ore')) {
                $warnings[] = __('Kapitalunderlaget för räntefördelning ser ut att vara lägre än minus 500 000 kr (uppskattat ur bokföringen vid förra årets slut: :amount). Negativ räntefördelning är tvingande (inkomstskattelagen 33 kap. 2 §). Fyll i kapitalunderlaget.', ['amount' => IncomeTaxForms::kronor($estimate)]);
            }
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

    /** @return list<int> the 3xxx accounts the owner marked as bil- och bostadsförmån (R3) */
    private function benefitAccounts(): array
    {
        $accounts = $this->input('benefit_accounts');

        return is_array($accounts) ? array_values(array_filter($accounts, 'is_int')) : [];
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
