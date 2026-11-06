<?php

declare(strict_types=1);

namespace App\Domain\Closing;

use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\Company\LegalForm;
use App\Domain\IncomeTax\IncomeTaxForms;
use App\Domain\IncomeTax\Ink2;
use App\Domain\IncomeTax\Ink2Codes;
use App\Domain\IncomeTax\TaxAllocationReserves;
use App\Models\ClosingCheck;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\IncomeTaxReturn;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The proposed income tax of a limited company or an ekonomisk förening in
 * the year-end closing (ADR-056), line by line: the book result before tax,
 * the INK2S adjustments (entered on the income tax page, or proposed from
 * the books), the reversal of old periodiseringsfonder, this year's
 * allocation, the taxable surplus rounded down to whole tens of kronor and
 * the tax at the year's rate. A proposal only: nothing is booked until a
 * person confirms it (YearEndClosing::confirmTaxProposal).
 *
 * Amounts in whole kronor like INK2, the tax in öre. Every rule comes from
 * CorporateTaxRules (database/data/corporate-tax/rules.json), none of it
 * legally verified.
 *
 * TODO(legal-verify): that a BAS fund account 2120–2139 is named after the
 * calendar year in which the fiscal year of the deduction ends, so that the
 * fund on 2120 + (year mod 10) is the one to reverse after six years; the
 * six years counted in fiscal years when a year was shorter or longer; that
 * the maximum allocation is rounded down to whole kronor; that the tax in
 * öre (the rounded income × the rate) is what is booked, not a tax rounded
 * to whole kronor.
 */
final class TaxProposal
{
    /** BAS 8810–8819 Förändring av periodiseringsfond. */
    public const array FUND_CHANGE_ACCOUNTS = [8810, 8819];

    /**
     * The person's answer to "is the company a privatbostadsföretag or an
     * investmentföretag?", kept per fiscal year as a closing check
     * (ClosingCheck key). Without the answer "neither" there is no proposal:
     * a privatbostadsföretag takes up no income and deducts no costs from its
     * property (IL 39 kap. 25 §, 2 kap. 17 §), an investmentföretag has its
     * own rules (IL 39 kap. 14 §), and neither may allocate to a
     * periodiseringsfond (IL 30 kap. 1 § andra stycket).
     */
    public const string STATUS_ORDINARY = 'tax_ordinary';

    public const string STATUS_PRIVATE_HOUSING = 'tax_private_housing';

    public const string STATUS_INVESTMENT = 'tax_investment';

    public const array STATUSES = [self::STATUS_ORDINARY, self::STATUS_PRIVATE_HOUSING, self::STATUS_INVESTMENT];

    /** @var ?list<array{account: int, year: ?int, kronor: int, ore: int, reversed_ore: int, mandatory: bool}> */
    private ?array $funds = null;

    private ?string $status = null;

    private function __construct(
        public readonly Company $company,
        public readonly FiscalYear $fiscalYear,
        public readonly Ink2 $ink2,
        private readonly AnnualAccounts $accounts,
        public readonly int $reversalKronor,
        public readonly int $allocationKronor,
    ) {}

    /**
     * @param  ?int  $reversalKronor  null proposes the reversal the law requires this year
     * @param  int  $allocationKronor  the allocation to a new fund the person chose; 0 by default
     */
    public static function for(Company $company, FiscalYear $fiscalYear, ?IncomeTaxReturn $return, ?int $reversalKronor = null, int $allocationKronor = 0): self
    {
        $accounts = AnnualAccounts::for($fiscalYear);
        $proposal = new self($company, $fiscalYear, new Ink2($company, $fiscalYear, $accounts, $return), $accounts, 0, 0);

        return new self($company, $fiscalYear, $proposal->ink2, $accounts, $reversalKronor ?? ($proposal->fundBookedByHand() ? 0 : $proposal->mandatoryReversalKronor()), $allocationKronor);
    }

    /** Only limited companies and ekonomiska föreningar get a proposal; both are juridiska personer taxed under IL 65 kap. 10 §. */
    public static function appliesTo(Company $company): bool
    {
        return in_array($company->legal_form, [LegalForm::Ab, LegalForm::EkonomiskForening], true);
    }

    /** The answer for the fiscal year, or null when nobody has answered. */
    public static function statusFor(FiscalYear $fiscalYear): ?string
    {
        $key = ClosingCheck::query()->where('fiscal_year_id', $fiscalYear->id)->whereIn('key', self::STATUSES)->value('key');

        return is_string($key) ? $key : null;
    }

    public function status(): ?string
    {
        $this->status ??= self::statusFor($this->fiscalYear) ?? '';

        return $this->status === '' ? null : $this->status;
    }

    /** Only "neither a privatbostadsföretag nor an investmentföretag" gives a proposal. */
    public function isOrdinary(): bool
    {
        return $this->status() === self::STATUS_ORDINARY;
    }

    /**
     * IL 30 kap. 6 a §: the company has funds at the start of the year but the
     * statslåneränta for the year is not in the data and nobody entered the
     * schablonintäkt (field 7654). The tax cannot be proposed then.
     */
    public function standardIncomeMissing(): bool
    {
        return TaxAllocationReserves::openingFundsOre($this->accounts) > 0
            && $this->ink2->proposals()[Ink2Codes::S_STANDARD_INCOME] === null
            && ! $this->ink2->isEntered(Ink2Codes::S_STANDARD_INCOME);
    }

    /**
     * Why the proposal cannot be confirmed, in Swedish; empty when it can.
     *
     * @return list<string>
     */
    public function blockers(): array
    {
        $blockers = [];

        if (! $this->isOrdinary()) {
            $blockers[] = __('Svara först på frågan om företaget är ett privatbostadsföretag eller ett investmentföretag.');
        }

        if ($this->rateBp() === null) {
            $blockers[] = __('NONOX har ingen skattesats för ett räkenskapsår som börjar :date. Bokför skatten för hand.', ['date' => $this->fiscalYear->start_date->toDateString()]);
        }

        if ($this->taxBookedByHand()) {
            $blockers[] = __('Det finns redan skatt bokförd på 8900–8989 i året. Rätta den verifikationen eller bokför resten för hand.');
        }

        if ($this->standardIncomeMissing()) {
            $blockers[] = __('Schablonintäkten på periodiseringsfonderna saknas (inkomstskattelagen 30 kap. 6 a §). Fyll i fält 7654 under Inkomstdeklaration först.');
        }

        if ($this->reversalKronor < $this->mandatoryReversalKronor()) {
            $blockers[] = $this->fundBookedByHand()
                ? __('En periodiseringsfond ska återföras senast det sjätte beskattningsåret efter avsättningen (inkomstskattelagen 30 kap. 7 §). :amount återstår att återföra i år. Bokför återföringen för hand först.', ['amount' => IncomeTaxForms::kronor($this->mandatoryReversalKronor())])
                : __('En periodiseringsfond ska återföras senast det sjätte beskattningsåret efter avsättningen (inkomstskattelagen 30 kap. 7 §). Återför minst :amount i år.', ['amount' => IncomeTaxForms::kronor($this->mandatoryReversalKronor())]);
        }

        return $blockers;
    }

    public function rateBp(): ?int
    {
        return CorporateTaxRules::rateFor($this->fiscalYear);
    }

    /** The book result before tax in whole kronor (INK2S 4.1/4.2 with the booked tax of 4.3 a added back). */
    public function resultBeforeTaxKronor(): int
    {
        return $this->ink2->resultKronor() + $this->ink2->taxKronor();
    }

    /** Tax booked on 8900–8989 without the closing step: the proposal is then not offered. */
    public function taxBookedByHand(): bool
    {
        return $this->accounts->movementOf(8900, 8989) !== 0;
    }

    /** A periodiseringsfond change already booked in the year: the fund part of the proposal is then left out. */
    public function fundBookedByHand(): bool
    {
        return $this->accounts->movementOf(self::FUND_CHANGE_ACCOUNTS[0], self::FUND_CHANGE_ACCOUNTS[1]) !== 0
            || $this->accounts->movementOf(TaxAllocationReserves::ACCOUNTS[0], TaxAllocationReserves::ACCOUNTS[1]) !== 0;
    }

    /**
     * The funds at the start of the year, oldest first (IL 30 kap. 7 § andra
     * stycket), by their BAS account, in öre as booked and in whole kronor;
     * reversed_ore is what was already reversed on the account this year by
     * hand. A fund outside 2120–2139 has no year.
     *
     * A fund is due when the fiscal year is the sixth beskattningsår or
     * later after the year of the deduction (IL 30 kap. 7 §): counted in the
     * company's fiscal years when the fund's year is among them, otherwise
     * in calendar years (TODO(legal-verify) in the class docblock).
     *
     * @return list<array{account: int, year: ?int, kronor: int, ore: int, reversed_ore: int, mandatory: bool}>
     */
    public function funds(): array
    {
        if ($this->funds !== null) {
            return $this->funds;
        }

        $endYear = $this->fiscalYear->end_date->year;
        $years = CorporateTaxRules::int('tax_allocation_reserve_reversal_years');
        $accounts = CorporateTaxRules::accounts('tax_allocation_reserve_accounts');
        $ends = FiscalYear::query()->whereDate('end_date', '<=', $this->fiscalYear->end_date->toDateString())->orderBy('end_date')->pluck('end_date')
            ->map(fn (mixed $date): string => CarbonImmutable::parse($date)->toDateString())->values()->all();
        $funds = [];

        foreach ($this->accounts->numbers() as $number) {
            if ($number < TaxAllocationReserves::ACCOUNTS[0] || $number > TaxAllocationReserves::ACCOUNTS[1]) {
                continue;
            }

            $ore = -$this->accounts->openingOf($number, $number);

            if ($ore <= 0) {
                continue;
            }

            $year = $number >= $accounts['first'] && $number <= $accounts['second'] + 9 ? self::yearOf($number, $endYear) : null;
            $funds[] = [
                'account' => $number,
                'year' => $year,
                'kronor' => Ink2::kronor($ore),
                'ore' => $ore,
                'reversed_ore' => max($this->accounts->movementOf($number, $number), 0),
                'mandatory' => $year !== null && self::yearsAfter($year, $endYear, $ends) >= $years,
            ];
        }

        usort($funds, fn (array $a, array $b): int => [$a['year'] ?? PHP_INT_MAX, $a['account']] <=> [$b['year'] ?? PHP_INT_MAX, $b['account']]);

        return $this->funds = $funds;
    }

    /**
     * How many beskattningsår after the fund's year the fiscal year is: the
     * company's fiscal years ending after the earliest one ending in the
     * fund's year, up to this one; calendar years when that year is not in
     * NONOX.
     *
     * @param  array<int, string>  $ends  end dates of the company's fiscal years up to this one, ascending
     */
    private static function yearsAfter(int $fundYear, int $endYear, array $ends): int
    {
        $fundEnd = collect($ends)->first(fn (string $end): bool => (int) substr($end, 0, 4) === $fundYear);

        if ($fundEnd === null) {
            return $endYear - $fundYear;
        }

        return count(array_filter($ends, fn (string $end): bool => $end > $fundEnd));
    }

    /** Funds with a year, less what was already reversed: these can be reversed here. */
    public function reversibleKronor(): int
    {
        return array_sum(array_map(fn (array $fund): int => Ink2::kronor(max($fund['ore'] - $fund['reversed_ore'], 0)), array_filter($this->funds(), fn (array $fund): bool => $fund['year'] !== null)));
    }

    /**
     * What the law still requires to be reversed this year: every fund six
     * beskattningsår old or more, less what was already reversed on its
     * account in the year (IL 30 kap. 7 §).
     */
    public function mandatoryReversalKronor(): int
    {
        return array_sum(array_map(fn (array $fund): int => Ink2::kronor(max($fund['ore'] - $fund['reversed_ore'], 0)), array_filter($this->funds(), fn (array $fund): bool => $fund['mandatory'])));
    }

    /**
     * The reversal spread over the funds, oldest first, in öre: a fund that
     * is reversed in full is reversed to the öre, so its account ends at 0.
     *
     * @return array<int, int> fund account => öre
     */
    public function reversals(): array
    {
        $left = $this->reversalKronor;
        $rows = [];

        foreach ($this->funds() as $fund) {
            $remaining = $fund['ore'] - $fund['reversed_ore'];

            if ($left <= 0 || $fund['year'] === null || $remaining <= 0) {
                continue;
            }

            $kronor = Ink2::kronor($remaining);
            $amount = min($left, $kronor);
            $rows[$fund['account']] = $amount === $kronor ? $remaining : $amount * 100;
            $left -= $amount;
        }

        return $rows;
    }

    /**
     * The voucher rows a confirmation books, debit positive in öre: the
     * reversal (fund account against 8819), the allocation (8811 against the
     * new fund account) and the tax (8910 against 2512). Empty when there is
     * nothing to book.
     *
     * @return list<array{account: int, amount: int, description?: string}>
     */
    public function voucherRows(): array
    {
        $accounts = CorporateTaxRules::accounts('tax_allocation_reserve_accounts');
        $rows = [];

        foreach ($this->reversals() as $number => $ore) {
            $rows[] = ['account' => $number, 'amount' => $ore, 'description' => __('Återföring av periodiseringsfond')];
            $rows[] = ['account' => $accounts['reversal'], 'amount' => -$ore, 'description' => __('Återföring av periodiseringsfond')];
        }

        $fundAccount = $this->allocationKronor > 0 ? $this->newFundAccount() : null;

        if ($fundAccount !== null) {
            $rows[] = ['account' => $accounts['allocation'], 'amount' => $this->allocationKronor * 100, 'description' => __('Avsättning till periodiseringsfond')];
            $rows[] = ['account' => $fundAccount, 'amount' => -$this->allocationKronor * 100, 'description' => __('Avsättning till periodiseringsfond')];
        }

        $tax = $this->taxOreOrNull() ?? 0;

        if ($tax > 0) {
            $rows[] = ['account' => ClosingAccounts::TAX_EXPENSE, 'amount' => $tax];
            $rows[] = ['account' => ClosingAccounts::TAX_LIABILITY, 'amount' => -$tax];
        }

        return $rows;
    }

    /** What a confirmation books, in Swedish, for the confirmation dialog. */
    public function confirmationText(): string
    {
        $parts = [];
        $reversals = $this->reversals();
        $accounts = CorporateTaxRules::accounts('tax_allocation_reserve_accounts');

        foreach ($reversals as $number => $ore) {
            $parts[] = __('återföring :amount på :fund mot :account', ['amount' => Money::ofOre($ore)->format(), 'fund' => $number, 'account' => $accounts['reversal']]);
        }

        $fundAccount = $this->allocationKronor > 0 ? $this->newFundAccount() : null;

        if ($fundAccount !== null) {
            $parts[] = __('avsättning :amount på :account mot :fund', ['amount' => Money::ofOre($this->allocationKronor * 100)->format(), 'account' => $accounts['allocation'], 'fund' => $fundAccount]);
        }

        $tax = $this->taxOreOrNull() ?? 0;

        if ($tax > 0) {
            $parts[] = __('skatt :amount på 8910 mot 2512', ['amount' => Money::ofOre($tax)->format()]);
        }

        $booked = $parts === []
            ? __('Ingen verifikation skapas; årets skatt sparas som noll.')
            : __('En verifikation skapas den :date med :rows. En bokförd verifikation kan inte ändras, bara rättas med en ny.', ['date' => $this->fiscalYear->end_date->toDateString(), 'rows' => implode('; ', $parts)]);

        return __('Bekräfta beräkningen? :booked Steget kan inte göras om.', ['booked' => $booked]);
    }

    /** The surplus before this year's allocation: after the adjustments, last year's deficit and the reversal (Skatteverket, Periodiseringsfond). */
    public function basisKronor(): int
    {
        return $this->ink2->taxableKronor() + $this->reversalKronor;
    }

    /** IL 30 kap. 5 §: at most 25 % of the surplus before the deduction, rounded down to whole kronor. */
    public function maxAllocationKronor(): int
    {
        if ($this->fundBookedByHand() || ! $this->isOrdinary()) {
            return 0;
        }

        return max(intdiv($this->basisKronor() * CorporateTaxRules::int('tax_allocation_reserve_share_bp'), 10_000), 0);
    }

    /** The taxable surplus (positive) or deficit (negative) after the allocation. */
    public function taxableKronor(): int
    {
        return $this->basisKronor() - $this->allocationKronor;
    }

    /** IL 1 kap. 7 §: rounded down to whole tens of kronor; nothing for a deficit. */
    public static function roundedTaxable(int $taxableKronor): int
    {
        $tens = CorporateTaxRules::int('taxable_income_rounding_kronor');

        return $taxableKronor > 0 ? intdiv($taxableKronor, $tens) * $tens : 0;
    }

    /** The tax in öre: whole kronor × basis points / 10 000, × 100 öre. Exact for a rate in tenths of a per cent on whole tens. */
    public static function taxOre(int $roundedKronor, int $rateBp): int
    {
        return intdiv($roundedKronor * $rateBp, 100);
    }

    /** The proposed tax in öre, or null when the rate for the year is missing. */
    public function taxOreOrNull(): ?int
    {
        $rate = $this->rateBp();

        return $rate === null ? null : self::taxOre(self::roundedTaxable($this->taxableKronor()), $rate);
    }

    /** The BAS account for this year's new fund: 2120 + the last digit of the year, or the "nr 2" account 2130 + digit when the first is taken. */
    public function newFundAccount(): ?int
    {
        $accounts = CorporateTaxRules::accounts('tax_allocation_reserve_accounts');
        $digit = $this->fiscalYear->end_date->year % 10;

        foreach ([$accounts['first'] + $digit, $accounts['second'] + $digit] as $number) {
            if ($this->accounts->openingOf($number, $number) === 0) {
                return $number;
            }
        }

        return null;
    }

    /**
     * The calculation line by line, in Swedish, for the page and the audit:
     * key, label, signed kronor, the INK2S field and where it comes from.
     *
     * @return list<array{key: string, label: string, kronor: int, field: ?int, origin: string, total: bool}>
     */
    public function lines(): array
    {
        $lines = [$this->line('result', __('Bokfört resultat före skatt'), $this->resultBeforeTaxKronor(), null, __('Bokföringen, konto 3000–8899'))];

        foreach ($this->ink2->adjustments() as $code => $amount) {
            $lines[] = $this->line("adjustment_{$code}", __(Ink2Codes::ADJUSTMENTS[$code][0]), Ink2Codes::ADJUSTMENTS[$code][1] * $amount, $code, $this->ink2->isEntered($code) ? __('Ifyllt under Inkomstdeklaration') : __('Förslag ur bokföringen'));
        }

        if ($this->reversalKronor !== 0) {
            $lines[] = $this->line('reversal', __('Återföring av periodiseringsfond'), $this->reversalKronor, null, __('Bokförs på 8819 (inkomstskattelagen 30 kap. 7 §)'));
        }

        $lines[] = $this->line('basis', __('Överskott före årets avsättning'), $this->basisKronor(), null, '', total: true);

        if ($this->allocationKronor !== 0) {
            $lines[] = $this->line('allocation', __('Avsättning till periodiseringsfond'), -$this->allocationKronor, null, __('Bokförs på 8811 (inkomstskattelagen 30 kap. 5 §)'));
        }

        $taxable = $this->taxableKronor();
        $lines[] = $this->line('taxable', $taxable >= 0 ? __('Skattemässigt överskott') : __('Skattemässigt underskott'), $taxable, $taxable >= 0 ? Ink2Codes::S_SURPLUS : Ink2Codes::S_DEFICIT, '', total: true);
        $lines[] = $this->line('rounded', __('Beskattningsbar inkomst, avrundad nedåt till helt tiotal kronor'), self::roundedTaxable($taxable), null, __('Inkomstskattelagen 1 kap. 7 §'));

        return $lines;
    }

    /**
     * What the person should look at before confirming, in Swedish.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        if ($this->fundBookedByHand()) {
            $warnings[] = __('En avsättning till eller återföring från periodiseringsfond är redan bokförd i året. Den räknas med i resultatet, och förslaget lägger inte till någon ny.');
        }

        $unknown = array_column(array_filter($this->funds(), fn (array $fund): bool => $fund['year'] === null), 'account');

        if ($unknown !== []) {
            $warnings[] = __('Periodiseringsfonder på :accounts har inget år i BAS-kontoplanen. Kontrollera när de ska återföras och bokför det för hand.', ['accounts' => implode(', ', $unknown)]);
        }

        return $warnings;
    }

    /** The largest year up to the fiscal year's end year whose last digit matches the account's. */
    private static function yearOf(int $account, int $endYear): int
    {
        return $endYear - (($endYear - $account % 10) % 10 + 10) % 10;
    }

    /** @return array{key: string, label: string, kronor: int, field: ?int, origin: string, total: bool} */
    private function line(string $key, string $label, int $kronor, ?int $field, string $origin, bool $total = false): array
    {
        return ['key' => $key, 'label' => $label, 'kronor' => $kronor, 'field' => $field, 'origin' => $origin, 'total' => $total];
    }
}
