<?php

declare(strict_types=1);

namespace App\Domain\Closing;

use App\Domain\Accounting\LedgerBalances;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\AnnualReport\K3Rules;
use App\Domain\Closing\DeferredTax\BuildingCarryingAmounts;
use App\Domain\Company\ReportingFramework;
use App\Models\Account;
use App\Models\Company;
use App\Models\DeferredTaxItem;
use App\Models\FiscalYear;
use App\Models\VoucherRow;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The proposed deferred tax of a K3 company at the balance date (ADR-059,
 * addendum 2026-11-07, A.2.6; rule sheet S1-R4, R4.1–R4.6), item by item:
 *
 * - the temporary difference = carrying amount − tax value (K3 29.14);
 * - its nature by the table in the 29.14 comment: for an asset a carrying
 *   amount above the tax value is taxable (a deferred tax liability) and one
 *   below it deductible (a deferred tax asset); for a liability the other way
 *   round. A tax-loss carry-forward is deductible (29.19 c);
 * - the deferred tax = the difference × the rate, rounded half up to the öre,
 *   never discounted (29.25, S1-R4.4);
 * - the rate of the beskattningsår that begins the day after the balance
 *   date (29.25: the rates decided before the balance date for the years in
 *   which the differences reverse), read from CorporateTaxRules. When the
 *   data holds a different rate for any later year, the proposal refuses
 *   until a person has decided how the reversals fall (rule sheet: medium);
 * - a deferred tax asset is never proposed: it is the amount a person
 *   recognised, with the reason, and never more than the computed asset
 *   (29.29, human decision H6; default 0);
 * - the liability (2240) and the asset (1370) are kept gross, netted only
 *   when the person confirms the legal right of set-off (29.32);
 * - the voucher books the difference between that and what the ledger holds
 *   on 2240–2249 and 1370–1379 against 8940 (29.22: the change goes to the
 *   income statement).
 *
 * Untaxed reserves are never an item (29.37); the item form refuses BAS
 * 2100–2199 and the database backs that up. A proposal only: nothing is
 * booked until a person confirms it (YearEndClosing::confirmDeferredTax).
 * Fixed query count: items, accounts, opening balances, the transition
 * voucher's correction chain (two), movements, and one call to the register.
 *
 * TODO(legal-verify): rules.json has no date on which a rate was decided; the
 * proposal assumes every rate in it was decided before the balance date of a
 * year it applies to (K3 29.25 "beslutade före balansdagen").
 * TODO(legal-verify): H5, K3 29.19 refers to "29.21 och 29.22" and 29.25 to
 * "29.28" where the content sits at 29.20/29.21 and 29.26; read as
 * cross-reference slips that change nothing for an AB without subsidiaries.
 * TODO(legal-verify): 29.21 (no deferred tax on the initial recognition of
 * goodwill) and 29.20 (investments in subsidiaries) are not detected; the
 * person leaves such differences out (the manual says so).
 */
final class DeferredTaxProposal
{
    public const string TAXABLE = 'TAXABLE';

    public const string DEDUCTIBLE = 'DEDUCTIBLE';

    public const string NONE = 'NONE';

    /** The closing check that records the person's confirmation of a legal right of set-off (K3 29.32). */
    public const string SET_OFF_CHECK = 'deferred_tax_set_off';

    public const string CARRYING_LEDGER = 'LEDGER';

    public const string CARRYING_REGISTER = 'REGISTER';

    public const string CARRYING_ENTERED = 'ENTERED';

    /** BAS 2100–2199 Obeskattade reserver: never an item in the legal entity (K3 29.37, S1-R4.2 c). */
    public const array UNTAXED_RESERVES = [2100, 2199];

    /** BAS 2000–2099 Eget kapital: not an asset or a liability. */
    public const array EQUITY = [2000, 2099];

    /** The deferred tax accounts themselves cannot be an item. */
    public const array LIABILITY_RANGE = [2240, 2249];

    public const array ASSET_RANGE = [1370, 1379];

    /**
     * @param  list<array{item: DeferredTaxItem, carrying_ore: ?int, carrying_source: string, tax_value_ore: int, difference_ore: int, nature: string, gross_ore: int, liability_ore: int, asset_ore: int, recognised_ore: int}>  $lines
     * @param  list<string>  $blockers
     */
    private function __construct(
        public readonly Company $company,
        public readonly FiscalYear $fiscalYear,
        public readonly ?int $rateBp,
        public readonly array $lines,
        public readonly array $blockers,
        public readonly bool $setOff,
        public readonly int $openingLiabilityOre,
        public readonly int $openingAssetOre,
        public readonly int $bookedLiabilityOre,
        public readonly int $bookedAssetOre,
    ) {}

    public static function for(Company $company, FiscalYear $fiscalYear, bool $setOff = false): self
    {
        $blockers = self::refusals($company, $fiscalYear);
        $rate = self::rateFor($fiscalYear);

        if (is_string($rate)) {
            $blockers[] = $rate;
            $rate = null;
        }

        $items = DeferredTaxItem::query()->where('fiscal_year_id', $fiscalYear->id)->orderBy('id')->get();
        $register = $items->contains(fn (DeferredTaxItem $item): bool => $item->fixed_asset_id !== null)
            ? app(BuildingCarryingAmounts::class)->forYear($fiscalYear)
            : [];

        $numbers = Account::query()->pluck('number', 'id')->map(fn (mixed $number): int => (int) $number);
        $opening = LedgerBalances::opening($fiscalYear);
        $closing = $opening->toBase();

        foreach (LedgerBalances::movements($fiscalYear) as $accountId => $movement) {
            $closing[$accountId] = ($closing[$accountId] ?? 0) + $movement;
        }

        /** @var array<int, int> $openingByNumber */
        $openingByNumber = [];
        /** @var array<int, int> $closingByNumber */
        $closingByNumber = [];

        // The K2-to-K3 transition voucher on the first day belongs to the opening balance sheet (K3 35.11, S1-P12):
        // the change since the last balance date is measured from the K3 opening, not from the K2 closing.
        foreach ([$opening, self::transitionMovements($fiscalYear)] as $balances) {
            foreach ($balances as $accountId => $ore) {
                $number = $numbers[$accountId] ?? null;

                if ($number !== null) {
                    $openingByNumber[$number] = ($openingByNumber[$number] ?? 0) + $ore;
                }
            }
        }

        foreach ($closing as $accountId => $ore) {
            $number = $numbers[$accountId] ?? null;

            if ($number !== null) {
                $closingByNumber[$number] = ($closingByNumber[$number] ?? 0) + (int) $ore;
            }
        }

        $lines = [];

        foreach ($items as $item) {
            [$carrying, $source] = self::carryingOf($item, $closingByNumber, $register);

            if ($carrying === null) {
                $blockers[] = __('Anläggningsregistret ger inget redovisat värde för posten ”:item”. Ange byggnadens konton i stället.', ['item' => $item->description]);
            }

            if ($item->tax_value_needs_review) {
                $blockers[] = __('Posten ”:item” är hämtad från föregående år. Kontrollera det skattemässiga värdet vid balansdagen och spara posten.', ['item' => $item->description]);
            }

            $taxValue = $item->tax_value_ore->ore;
            $difference = ($carrying ?? 0) - $taxValue;
            $nature = self::nature($item->kind, $carrying ?? 0, $taxValue);
            $gross = $rate === null ? 0 : self::deferredTax($difference, $rate);
            $recognised = $nature === self::DEDUCTIBLE ? min($gross, $item->recognised_ore->ore) : 0;

            $lines[] = [
                'item' => $item,
                'carrying_ore' => $carrying,
                'carrying_source' => $source,
                'tax_value_ore' => $taxValue,
                'difference_ore' => $difference,
                'nature' => $nature,
                'gross_ore' => $gross,
                'liability_ore' => $nature === self::TAXABLE ? $gross : 0,
                'asset_ore' => $recognised,
                'recognised_ore' => $item->recognised_ore->ore,
            ];
        }

        return new self(
            $company,
            $fiscalYear,
            $rate,
            $lines,
            array_values(array_unique($blockers)),
            $setOff,
            -self::sumRange($openingByNumber, self::LIABILITY_RANGE),
            self::sumRange($openingByNumber, self::ASSET_RANGE),
            -self::sumRange($closingByNumber, self::LIABILITY_RANGE),
            self::sumRange($closingByNumber, self::ASSET_RANGE),
        );
    }

    /**
     * Debit minus credit per account of the year's posted K2-to-K3
     * transition voucher (VoucherSource::K3Transition), netted with every
     * posted voucher in its correction chain in the year (C1 correcting it,
     * C2 correcting C1, …). Two queries: the chain, then the rows grouped.
     *
     * @return array<int, int>
     */
    private static function transitionMovements(FiscalYear $fiscalYear): array
    {
        // The whole correction chain of the transition voucher, not only its direct corrections: a
        // standing transition corrected with re-booking by C1 may have C1 corrected by C2, and the
        // K3 opening balance is the net of all of them. (A void transition's chain cannot grow:
        // VoidK3Transition::refuseCorrection.) Raw SQL for the recursive query; limited to the
        // year's own company explicitly.
        $chain = array_map(fn (stdClass $row): string => (string) $row->id, DB::select(<<<'SQL'
            WITH RECURSIVE chain AS (
                SELECT id FROM vouchers
                WHERE company_id = ? AND fiscal_year_id = ? AND source = ?
                UNION
                SELECT vouchers.id FROM vouchers
                JOIN chain ON vouchers.corrects_voucher_id = chain.id
                WHERE vouchers.company_id = ?
            )
            SELECT id FROM chain
            SQL, [$fiscalYear->company_id, $fiscalYear->id, VoucherSource::K3Transition->value, $fiscalYear->company_id]));

        if ($chain === []) {
            return [];
        }

        return VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->where('vouchers.fiscal_year_id', $fiscalYear->id)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereIn('vouchers.id', $chain)
            ->groupBy('voucher_rows.account_id')
            ->selectRaw('voucher_rows.account_id, SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) AS movement')
            ->pluck('movement', 'account_id')
            ->map(fn (mixed $movement): int => (int) $movement)
            ->all();
    }

    /**
     * Why there can be no proposal for the year at all, in Swedish.
     *
     * @return list<string>
     */
    public static function refusals(Company $company, FiscalYear $fiscalYear): array
    {
        $refusals = [];

        if ($fiscalYear->reporting_framework !== ReportingFramework::K3) {
            $refusals[] = __('Uppskjuten skatt redovisas bara av företag som tillämpar K3. Räkenskapsåret :year följer :framework.', ['year' => $fiscalYear->label(), 'framework' => $fiscalYear->reporting_framework->value]);
        }

        if (! TaxProposal::appliesTo($company)) {
            $refusals[] = ClosingAccounts::isOwnerTaxed($company)
                ? __('Företagsformen betalar ingen egen inkomstskatt; ägarna beskattas. Då finns ingen uppskjuten skatt i företaget.')
                : __('NONOX räknar bara fram uppskjuten skatt för aktiebolag och ekonomiska föreningar. Beräkna den för hand.');
        }

        return $refusals;
    }

    /**
     * The rate in basis points for the beskattningsår that begins the day
     * after the balance date, or the Swedish reason there is none.
     */
    public static function rateFor(FiscalYear $fiscalYear): int|string
    {
        return self::rateForStart($fiscalYear->end_date->addDay());
    }

    /**
     * The rate in basis points for the beskattningsår that begins on $next,
     * or the Swedish reason there is none; also used at the K2-to-K3
     * transition date (K3TransitionProposal).
     */
    public static function rateForStart(CarbonImmutable $next): int|string
    {
        $entry = CorporateTaxRules::rateEntryForStart($next);

        if ($entry === null) {
            return __('Det finns ingen bolagsskattesats för räkenskapsår som börjar :date. Beräkna den uppskjutna skatten för hand.', ['date' => $next->toDateString()]);
        }

        foreach (CorporateTaxRules::rates() as $rate) {
            if ($rate !== $entry && ($rate['starts_before'] === null || $rate['starts_before'] > $next->toDateString())) {
                return __('Skattesatsen ändras för senare år (:rate från :date). NONOX kan inte avgöra vilka skillnader som återförs när. Beräkna den uppskjutna skatten för hand.', ['rate' => self::percent($rate['rate_bp']), 'date' => $rate['starts_from']]);
            }
        }

        if ($entry['starts_before'] !== null) {
            return __('Skattesatsen :rate gäller bara räkenskapsår som börjar före :date. NONOX kan inte avgöra vilka skillnader som återförs när. Beräkna den uppskjutna skatten för hand.', ['rate' => self::percent($entry['rate_bp']), 'date' => $entry['starts_before']]);
        }

        return $entry['rate_bp'];
    }

    /**
     * The table in the K3 29.14 comment (S1-R4.1). A loss carry-forward has
     * no carrying amount, so its tax value makes it deductible.
     */
    public static function nature(string $kind, int $carryingOre, int $taxValueOre): string
    {
        $difference = $carryingOre - $taxValueOre;

        if ($difference === 0) {
            return self::NONE;
        }

        if (DeferredTaxItem::isAssetKind($kind)) {
            return $difference > 0 ? self::TAXABLE : self::DEDUCTIBLE;
        }

        return $difference > 0 ? self::DEDUCTIBLE : self::TAXABLE;
    }

    /** The deferred tax on a temporary difference: its size × the rate, half up to the öre, not discounted (K3 29.25). */
    public static function deferredTax(int $differenceOre, int $rateBp): int
    {
        return Money::ofOre(abs($differenceOre))->percent($rateBp)->ore;
    }

    /** Whether an account range touches BAS 2100–2199 (untaxed reserves). */
    public static function touchesUntaxedReserves(int $from, int $to): bool
    {
        return self::overlaps($from, $to, self::UNTAXED_RESERVES);
    }

    /** @param  array{0: int, 1: int}  $range */
    public static function overlaps(int $from, int $to, array $range): bool
    {
        return $from <= $range[1] && $to >= $range[0];
    }

    /** The gross deferred tax liability: every taxable difference. */
    public function liabilityOre(): int
    {
        return array_sum(array_column($this->lines, 'liability_ore'));
    }

    /** The deferred tax asset: what people recognised, never more than computed. */
    public function assetOre(): int
    {
        return array_sum(array_column($this->lines, 'asset_ore'));
    }

    /** The computed asset before recognition, shown beside the recognised amount (H6). */
    public function grossAssetOre(): int
    {
        return array_sum(array_map(fn (array $line): int => $line['nature'] === self::DEDUCTIBLE ? $line['gross_ore'] : 0, $this->lines));
    }

    /** What 2240–2249 should hold at the balance date, as presented (credit positive). */
    public function targetLiabilityOre(): int
    {
        return $this->setOff ? max($this->liabilityOre() - $this->assetOre(), 0) : $this->liabilityOre();
    }

    /** What 1370–1379 should hold at the balance date. */
    public function targetAssetOre(): int
    {
        return $this->setOff ? max($this->assetOre() - $this->liabilityOre(), 0) : $this->assetOre();
    }

    /** The change of the net deferred tax since the last balance date (ÅRL 5 kap. 36 §), a cost positive. */
    public function changeSinceOpeningOre(): int
    {
        return ($this->targetLiabilityOre() - $this->targetAssetOre()) - ($this->openingLiabilityOre - $this->openingAssetOre);
    }

    /** What the voucher books on 8940: a cost positive, an income negative. */
    public function expenseOre(): int
    {
        return ($this->targetLiabilityOre() - $this->bookedLiabilityOre) - ($this->targetAssetOre() - $this->bookedAssetOre);
    }

    public function isBlocked(): bool
    {
        return $this->blockers !== [];
    }

    /**
     * No items, but the ledger holds deferred tax: booking would reverse all
     * of it as income or expense. Only with the person's explicit
     * confirmation (YearEndClosing::confirmDeferredTax, $reverseAll).
     */
    public function reversesEverything(): bool
    {
        return $this->lines === [] && ($this->bookedLiabilityOre !== 0 || $this->bookedAssetOre !== 0);
    }

    /**
     * What the person saw: the targets, the amount to book and every item as
     * it was then. Confirmation is refused when it differs (S1-P5 review).
     */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            $this->targetLiabilityOre(),
            $this->targetAssetOre(),
            $this->expenseOre(),
            $this->bookedLiabilityOre,
            $this->bookedAssetOre,
            array_map(fn (array $line): array => [$line['item']->id, $line['item']->updated_at?->toIso8601String(), $line['carrying_ore'], $line['tax_value_ore'], $line['recognised_ore']], $this->lines),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The voucher rows (signed öre, debit positive): 2240 and 1370 to their
     * targets, 8940 against. Empty when nothing changes.
     *
     * @return list<array{account: int, amount: int}>
     */
    public function voucherRows(): array
    {
        $liability = -($this->targetLiabilityOre() - $this->bookedLiabilityOre);
        $asset = $this->targetAssetOre() - $this->bookedAssetOre;

        $rows = array_values(array_filter([
            ['account' => K3Rules::int('deferred_tax.expense_account'), 'amount' => -($liability + $asset)],
            ['account' => K3Rules::int('deferred_tax.liability_account'), 'amount' => $liability],
            ['account' => K3Rules::int('deferred_tax.asset_account'), 'amount' => $asset],
        ], fn (array $row): bool => $row['amount'] !== 0));

        return $rows;
    }

    /** The Swedish text of the confirmation dialog, stating what will be booked and that it cannot be undone. */
    public function confirmationText(): string
    {
        $expense = $this->expenseOre();

        if ($expense === 0) {
            return __('Bekräfta att den uppskjutna skatten för :year är :liability i skuld och :asset i fordran och att inget behöver bokas? Bekräftelsen kan inte ändras efteråt.', [
                'year' => $this->fiscalYear->label(),
                'liability' => Money::ofOre($this->targetLiabilityOre())->format(),
                'asset' => Money::ofOre($this->targetAssetOre())->format(),
            ]);
        }

        return __('Bokföra förändringen av uppskjuten skatt, :amount :direction på konto 8940, den :date? Uppskjuten skatteskuld blir :liability och uppskjuten skattefordran :asset. En bokförd verifikation kan inte ändras, bara rättas med en ny.', [
            'amount' => Money::ofOre(abs($expense))->format(),
            'direction' => $expense > 0 ? __('som kostnad') : __('som intäkt'),
            'date' => $this->fiscalYear->end_date->toDateString(),
            'liability' => Money::ofOre($this->targetLiabilityOre())->format(),
            'asset' => Money::ofOre($this->targetAssetOre())->format(),
        ]);
    }

    public static function percent(int $basisPoints): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($basisPoints / 100, 2, '.', ''), '0'), '.')).' %';
    }

    /**
     * @param  array<int, int>  $balances  account number => signed öre
     * @param  array<int, array{label: string, carrying_ore: int}>  $register
     * @return array{0: ?int, 1: string}
     */
    private static function carryingOf(DeferredTaxItem $item, array $balances, array $register): array
    {
        if ($item->kind === DeferredTaxItem::LOSS_CARRY_FORWARD) {
            return [0, self::CARRYING_ENTERED];
        }

        if ($item->fixed_asset_id !== null) {
            return [$register[$item->fixed_asset_id]['carrying_ore'] ?? null, self::CARRYING_REGISTER];
        }

        if ($item->account_from !== null && $item->account_to !== null) {
            $sum = self::sumRange($balances, [$item->account_from, $item->account_to]);

            // A liability is presented credit positive.
            return [DeferredTaxItem::isAssetKind($item->kind) ? $sum : -$sum, self::CARRYING_LEDGER];
        }

        return [$item->carrying_ore->ore ?? 0, self::CARRYING_ENTERED];
    }

    /**
     * @param  array<int, int>  $balances  account number => signed öre
     * @param  array{0: int, 1: int}  $range
     */
    private static function sumRange(array $balances, array $range): int
    {
        $sum = 0;

        foreach ($balances as $number => $ore) {
            if ($number >= $range[0] && $number <= $range[1]) {
                $sum += $ore;
            }
        }

        return $sum;
    }
}
