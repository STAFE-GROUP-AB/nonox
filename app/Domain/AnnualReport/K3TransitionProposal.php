<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use App\Actions\AnnualReport\SaveFrameworkChoice;
use App\Actions\Closing\DeferredTaxItems;
use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\Accounting\StaleOpeningBalances;
use App\Domain\Assets\AssetCategory;
use App\Domain\Closing\DeferredTaxProposal;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\K3Transition;
use App\Models\K3TransitionItem;
use App\Models\OpeningBalance;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The K2-to-K3 transition of a company's first K3 fiscal year (ADR-059 A.2.8,
 * package S1-P12; rule sheet S1-R7, R7.1–R7.7; BFN's consolidated K3
 * vl12-1-k3-kons20251215, chapter 35, read 2026-11-07).
 *
 * K3 35.5: "En förstagångstillämpare ska upprätta en ingångsbalansräkning."
 * 35.8: its items are restated retroactively "om inte annat framgår av
 * punkterna 35.11–35.30", and 35.11: "Finns det skillnader mellan de
 * redovisningsprinciper som ett företag tidigare har tillämpat och principerna
 * enligt detta allmänna råd, ska de skillnadsbelopp som uppkommer redovisas
 * direkt mot eget kapital i ingångsbalansräkningen."
 *
 * What the proposal holds:
 * - the transition date: the first day of the first K3 year. NONOX supports
 *   only the S1-R7.1 branch where comparatives are not restated (K3 35.7 for
 *   a mindre företag using ÅRL 3 kap. 5 § fjärde stycket); the policy
 *   `comparatives_restated_at_transition` = `yes` blocks (ADR-059 A.2.3, "`no`
 *   first"), so no comparative year is ever restated, components included
 *   (review P2-2);
 * - per item (K3TransitionItem) the difference against equity: the opening
 *   deferred tax on a temporary difference at the transition date, carrying
 *   amount from the opening balances (= the last K2 year's closing balances)
 *   or entered, at the rate of the beskattningsår beginning on the transition
 *   date (29.25), a deferred tax asset only as recognised by a person (H6);
 *   the 35.27 relief only with a reason, always disclosed (35.31); a provision
 *   (K3 chapter 21) and any other item with its K3 reference. Restatements
 *   35.15, 35.16, 35.18 and 35.20 forbid are refused when entered
 *   (K3ForbiddenRestatement);
 * - the components: a building in the register must be split on the
 *   transition date (35.18, H4); the split allocates the carrying amount and
 *   is no transition item, so the sheet shows it with an equity difference of
 *   0 (S1-R7.2 a);
 * - the reconciliation sheet per K3 balance sheet line (K2 closing balance,
 *   adjustment, K3 opening balance), equity and the balance sheet total;
 * - the disclosures (S1-R7.5): first application (35.31), comparatives not
 *   restated (35.7), reliefs used (35.31), items not restated (35.30: none,
 *   NONOX offers no such item), changed principles (35.33);
 * - the voucher (H7 (a)): source K3_TRANSITION, dated the transition date,
 *   every difference against 2091 (k3-rules.json `transition.equity_account`).
 *   The opening balances stay equal to the last K2 year's closing balances.
 *
 * Chapter 35 is used once (35.1), but without that limit by a company forced
 * off K2 by BFNAR 2025:2 (BFNAR 2025:3 transitional provision 2, S1-R7.6;
 * K3Policies::chapter35Allowed()); a booked transition in another year counts
 * as an earlier use.
 *
 * A proposal only: YearEndClosing books nothing here; BookK3Transition books
 * it once, after a person confirms what they saw (fingerprint()). Fixed
 * query count: policies, transitions, items, accounts, opening balances, the
 * buildings with their components, and StaleOpeningBalances' four queries.
 *
 * TODO(legal-verify): S1-R7.7 / H7 (a): a voucher dated the first day against
 * 2091 rather than an IB adjustment, and 2091 as the account (BAS 2026).
 * TODO(legal-verify): the 35.27 relief applied per item (35.27 says "En
 * uppskjuten skatteskuld eller en uppskjuten skattefordran …"), not only to
 * all deferred tax at once.
 * TODO(legal-verify): reliefs 35.23, 35.28, 35.29 and 35.30 are not offered;
 * the comparability note says no item was left unrestated under 35.30.
 * TODO(legal-verify): the K3 6.3 row of the transition in the equity
 * statement (b, ADR-059 A.1 point 5).
 */
final class K3TransitionProposal
{
    public const string TAXABLE = DeferredTaxProposal::TAXABLE;

    public const string DEDUCTIBLE = DeferredTaxProposal::DEDUCTIBLE;

    public const string NONE = DeferredTaxProposal::NONE;

    /** The 35.27 relief: no deferred tax recognised for the item. */
    public const string RELIEF = 'RELIEF';

    /**
     * @param  list<array{item: K3TransitionItem, carrying_ore: ?int, tax_value_ore: ?int, difference_ore: ?int, nature: ?string, gross_ore: int, liability_ore: int, asset_ore: int, equity_ore: int, rows: list<array{account: int, amount: int, description: string}>}>  $lines
     * @param  list<string>  $blockers
     * @param  list<array{asset: FixedAsset, status: string, carrying_before_ore: int, carrying_after_ore: int, split_on: ?CarbonImmutable}>  $buildings
     * @param  array<int, int>  $openingByNumber  account number => signed öre at the transition date, before the transition
     */
    private function __construct(
        public readonly Company $company,
        public readonly FiscalYear $fiscalYear,
        public readonly ?FiscalYear $previous,
        public readonly CarbonImmutable $transitionDate,
        public readonly ?int $rateBp,
        public readonly array $lines,
        public readonly array $blockers,
        public readonly array $buildings,
        public readonly array $openingByNumber,
        public readonly ?K3Transition $booked,
        public readonly bool $chapter35Repeated,
    ) {}

    public static function for(Company $company, FiscalYear $fiscalYear): self
    {
        $previous = DeferredTaxItems::previousYear($fiscalYear);
        $transitionDate = $fiscalYear->start_date;
        $blockers = [];
        $policies = K3Policies::forYear($fiscalYear);
        $transitions = K3Transition::query()->orderBy('id')->get();
        $booked = $transitions->firstWhere('fiscal_year_id', $fiscalYear->id);
        $earlier = $transitions->first(fn (K3Transition $transition): bool => $transition->fiscal_year_id !== $fiscalYear->id);
        $forced = $policies->value(K3PolicyKey::LeftK2Reason) === K3LeftK2Reason::Forced20252;

        if ($booked === null) {
            $blockers = [...$blockers, ...self::policyBlockers($policies, $earlier, $forced)];
            $blockers = [...$blockers, ...self::ledgerBlockers($company, $fiscalYear, $previous)];
        }

        $items = K3TransitionItem::query()->where('fiscal_year_id', $fiscalYear->id)->orderBy('id')->get();
        $needsRate = $items->contains(fn (K3TransitionItem $item): bool => $item->kind === K3TransitionItem::DEFERRED_TAX && ! $item->relief_35_27);
        $rate = $booked?->rate_bp;

        if ($rate === null && $needsRate) {
            $rate = DeferredTaxProposal::rateForStart($transitionDate);

            if (is_string($rate)) {
                $blockers[] = $rate;
                $rate = null;
            }
        }

        $numbers = Account::query()->pluck('number', 'id')->map(fn (mixed $number): int => (int) $number);
        $openingByNumber = [];

        foreach (OpeningBalance::query()->where('fiscal_year_id', $fiscalYear->id)->toBase()->get(['account_id', 'amount_ore']) as $row) {
            $number = $numbers[(int) $row->account_id] ?? null;

            if ($number !== null) {
                $openingByNumber[$number] = ($openingByNumber[$number] ?? 0) + (int) $row->amount_ore;
            }
        }

        $equityAccount = self::equityAccount($transitionDate);
        $lines = [];

        foreach ($items as $item) {
            $lines[] = self::line($item, $openingByNumber, $rate, $equityAccount);
        }

        $buildings = self::buildings($transitionDate);

        if ($booked === null) {
            foreach ($buildings as $building) {
                $blocker = self::buildingBlocker($building, $transitionDate);

                if ($blocker !== null) {
                    $blockers[] = $blocker;
                }
            }
        }

        return new self(
            $company,
            $fiscalYear,
            $previous,
            $transitionDate,
            $rate,
            $lines,
            array_values(array_unique($blockers)),
            $buildings,
            $openingByNumber,
            $booked,
            $earlier !== null && $forced,
        );
    }

    /**
     * Why there can be no transition for the year at all, in Swedish: it is
     * not K3, it is not the first K3 year after K2, or the company is not an
     * aktiebolag or ekonomisk förening (the only forms S1 supports).
     *
     * @return list<string>
     */
    public static function refusals(Company $company, FiscalYear $fiscalYear): array
    {
        $refusals = [];

        if ($fiscalYear->reporting_framework !== ReportingFramework::K3) {
            $refusals[] = __('Övergången till K3 görs det första räkenskapsåret som företaget följer K3. Räkenskapsåret :year följer :framework.', ['year' => $fiscalYear->label(), 'framework' => $fiscalYear->reporting_framework->value]);

            return $refusals;
        }

        $previous = DeferredTaxItems::previousYear($fiscalYear);

        if (! SaveFrameworkChoice::isFirstK3Year($fiscalYear, $previous)) {
            $refusals[] = __('Räkenskapsåret :year är inte företagets första år med K3: även :previous följer K3. Övergången görs bara det första året.', ['year' => $fiscalYear->label(), 'previous' => $previous?->label()]);
        }

        if (! in_array($company->legal_form, [LegalForm::Ab, LegalForm::EkonomiskForening], true)) {
            $refusals[] = __('NONOX stöder övergången till K3 för aktiebolag och ekonomiska föreningar.');
        }

        return $refusals;
    }

    /** The equity account the differences are booked against (H7 (a), k3-rules.json). */
    public static function equityAccount(CarbonImmutable $transitionDate): int
    {
        return K3Rules::int('transition.equity_account', $transitionDate);
    }

    public function isBlocked(): bool
    {
        return $this->blockers !== [];
    }

    public function isBooked(): bool
    {
        return $this->booked !== null;
    }

    /** The change in equity at the transition date, credit positive (a decrease is negative). */
    public function equityEffectOre(): int
    {
        return array_sum(array_column($this->lines, 'equity_ore'));
    }

    /**
     * The voucher rows in item order, signed öre (debit positive), each item
     * against the equity account.
     *
     * @return list<array{account: int, amount: int, description: string}>
     */
    public function voucherRows(): array
    {
        $rows = [];

        foreach ($this->lines as $line) {
            array_push($rows, ...$line['rows']);
        }

        return $rows;
    }

    /**
     * The reconciliation sheet: per K3 balance sheet line that the transition
     * changes, the K2 amount at the transition date, the adjustment and the K3
     * opening amount, each as presented (assets debit positive, equity and
     * liabilities credit positive); then equity and the balance sheet total.
     *
     * @return array{lines: list<array{key: string, label: string, k2_ore: int, adjustment_ore: int, k3_ore: int}>, equity_k2_ore: int, equity_k3_ore: int, total_k2_ore: int, total_k3_ore: int}
     */
    public function reconciliation(): array
    {
        $adjustments = [];

        foreach ($this->voucherRows() as $row) {
            $adjustments[$row['account']] = ($adjustments[$row['account']] ?? 0) + $row['amount'];
        }

        $assetSections = K3Schedule::ASSET_SECTIONS;
        $equitySections = ['restricted', 'free'];
        $lines = [];
        $equityK2 = 0;
        $totalK2 = 0;
        $equityAdjustment = 0;
        $totalAdjustment = 0;

        foreach ($this->openingByNumber as $number => $ore) {
            $line = self::lineOf($number);

            if ($line === null) {
                // 2099 (the year's result) is equity, kept out of the schedule's lines.
                if ($number === K3EquityStatement::YEAR_RESULT_ACCOUNT) {
                    $equityK2 -= $ore;
                }

                continue;
            }

            if (in_array($line['section'], $equitySections, true)) {
                $equityK2 -= $ore;
            }

            if (in_array($line['section'], $assetSections, true)) {
                $totalK2 += $ore;
            }
        }

        foreach (K3Schedule::BALANCE as $line) {
            $asset = in_array($line['section'], $assetSections, true);
            $k2 = 0;
            $adjustment = 0;

            foreach ($this->openingByNumber as $number => $ore) {
                if (self::inRanges($number, $line['ranges'])) {
                    $k2 += $asset ? $ore : -$ore;
                }
            }

            foreach ($adjustments as $number => $ore) {
                if (self::inRanges($number, $line['ranges'])) {
                    $adjustment += $asset ? $ore : -$ore;
                }
            }

            if ($adjustment === 0) {
                continue;
            }

            if (in_array($line['section'], $equitySections, true)) {
                $equityAdjustment += $adjustment;
            }

            if ($asset) {
                $totalAdjustment += $adjustment;
            }

            $lines[] = ['key' => $line['key'], 'label' => __($line['label']), 'k2_ore' => $k2, 'adjustment_ore' => $adjustment, 'k3_ore' => $k2 + $adjustment];
        }

        return [
            'lines' => $lines,
            'equity_k2_ore' => $equityK2,
            'equity_k3_ore' => $equityK2 + $equityAdjustment,
            'total_k2_ore' => $totalK2,
            'total_k3_ore' => $totalK2 + $totalAdjustment,
        ];
    }

    /**
     * The disclosures of the first K3 annual report (S1-R7.1, R7.5), as
     * structured data; the K3 document (S1-P10) prints comparabilityNote().
     *
     * @return array{first_time: bool, comparatives_restated: bool, reliefs: list<string>, relief_items: list<string>, not_restated_35_30: list<string>, changed_principles: list<string>, equity_effect_ore: int, transition_date: string, previous_label: ?string}
     */
    public function disclosures(): array
    {
        $reliefItems = [];
        $changed = [];

        foreach ($this->lines as $line) {
            $item = $line['item'];

            if ($item->kind === K3TransitionItem::DEFERRED_TAX && $item->relief_35_27) {
                $reliefItems[] = $item->description;
            }

            $changed[] = match ($item->kind) {
                K3TransitionItem::DEFERRED_TAX => 'deferred_tax',
                K3TransitionItem::PROVISION => 'provisions',
                default => (string) $item->k3_reference,
            };
        }

        if (array_filter($this->buildings, fn (array $building): bool => $building['status'] === 'split') !== []) {
            $changed[] = 'components';
        }

        return [
            'first_time' => true,
            'comparatives_restated' => false,
            'reliefs' => $reliefItems === [] ? [] : ['K3 35.27'],
            'relief_items' => $reliefItems,
            'not_restated_35_30' => [],
            'changed_principles' => array_values(array_unique($changed)),
            'equity_effect_ore' => $this->equityEffectOre(),
            'transition_date' => $this->transitionDate->toDateString(),
            'previous_label' => $this->previous?->label(),
        ];
    }

    /**
     * The note on the first application of K3 and the lacking comparability,
     * in Swedish, one sentence per paragraph (K3 35.7, 35.31, 35.33; ÅRL 3 kap.
     * 5 § fjärde stycket).
     *
     * TODO(legal-verify): the wording is NONOX's own; K3 gives none.
     *
     * @return list<string>
     */
    public function comparabilityNote(): array
    {
        $disclosures = $this->disclosures();
        $note = [__('Årsredovisningen upprättas för första gången enligt Bokföringsnämndens allmänna råd BFNAR 2012:1 Årsredovisning och koncernredovisning (K3). Tidpunkten för övergång är :date.', ['date' => $disclosures['transition_date']])];

        $note[] = $disclosures['previous_label'] === null
            ? __('Jämförelsetalen har inte räknats om enligt K3, och jämförbarheten med föregående räkenskapsår är därför begränsad.')
            : __('Jämförelsetalen för räkenskapsåret :previous har inte räknats om och är upprättade enligt BFNAR 2016:10 Årsredovisning i mindre företag (K2). Jämförbarheten mellan åren är därför begränsad.', ['previous' => $disclosures['previous_label']]);

        $principles = [];

        foreach ($disclosures['changed_principles'] as $principle) {
            $principles[] = match ($principle) {
                'deferred_tax' => __('uppskjuten skatt redovisas på temporära skillnader (K3 kapitel 29)'),
                'provisions' => __('avsättningar redovisas även för informella förpliktelser (K3 kapitel 21)'),
                'components' => __('byggnader skrivs av per betydande komponent (K3 punkt 17.4)'),
                default => __('poster enligt :reference', ['reference' => $principle]),
            };
        }

        if ($principles !== []) {
            $note[] = __('Följande redovisningsprinciper har ändrats vid övergången: :principles.', ['principles' => implode('; ', $principles)]);
        }

        if ($disclosures['relief_items'] !== []) {
            $note[] = __('Lättnadsregeln i K3 punkt 35.27 har tillämpats: uppskjuten skatt har inte redovisats i ingångsbalansräkningen för :items, eftersom uppgifterna inte kan tas fram utan oskälig kostnad.', ['items' => implode(', ', $disclosures['relief_items'])]);
        } else {
            $note[] = __('Inga lättnadsregler i K3 punkterna 35.21–35.30 har tillämpats.');
        }

        $effect = $disclosures['equity_effect_ore'];
        $note[] = $effect === 0
            ? __('Övergången har inte påverkat eget kapital.')
            : __('Övergången har :direction eget kapital den :date med :amount, redovisat direkt mot balanserad vinst (K3 punkt 35.11).', [
                'direction' => $effect < 0 ? __('minskat') : __('ökat'),
                'date' => $disclosures['transition_date'],
                'amount' => Money::ofOre(abs($effect))->format(),
            ]);

        return $note;
    }

    /**
     * What the person saw: every item as it was, the amounts, the rate and
     * the buildings. Booking is refused when it differs.
     */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            $this->rateBp,
            $this->equityEffectOre(),
            $this->voucherRows(),
            array_map(fn (array $line): array => [$line['item']->id, $line['item']->updated_at?->toIso8601String()], $this->lines),
            array_map(fn (array $building): array => [$building['asset']->id, $building['status'], $building['carrying_before_ore'], $building['carrying_after_ore']], $this->buildings),
        ], JSON_THROW_ON_ERROR));
    }

    /** The Swedish text of the confirmation dialog: what is booked, when, against what, and that it is final. */
    public function confirmationText(): string
    {
        $effect = $this->equityEffectOre();
        $equityAccount = self::equityAccount($this->transitionDate);

        if ($this->voucherRows() === []) {
            return __('Bekräfta övergången till K3 per :date utan någon bokföring? Övergången kan bara göras en gång och bekräftelsen kan inte ändras efteråt. Räkenskapsåret :previous ändras inte.', [
                'date' => $this->transitionDate->toDateString(),
                'previous' => $this->previous?->label() ?? __('före'),
            ]);
        }

        return __('Bokföra övergången till K3 som en verifikation den :date? Eget kapital :direction med :amount på konto :account. Övergången kan bara bokföras en gång, och en bokförd verifikation kan inte ändras, bara rättas med en ny. Räkenskapsåret :previous och dess årsredovisning ändras inte.', [
            'date' => $this->transitionDate->toDateString(),
            'direction' => $effect < 0 ? __('minskar') : __('ökar'),
            'amount' => Money::ofOre(abs($effect))->format(),
            'account' => $equityAccount,
            'previous' => $this->previous?->label() ?? __('före'),
        ]);
    }

    /**
     * @param  array<int, int>  $opening  account number => signed öre
     * @return array{item: K3TransitionItem, carrying_ore: ?int, tax_value_ore: ?int, difference_ore: ?int, nature: ?string, gross_ore: int, liability_ore: int, asset_ore: int, equity_ore: int, rows: list<array{account: int, amount: int, description: string}>}
     */
    private static function line(K3TransitionItem $item, array $opening, ?int $rate, int $equityAccount): array
    {
        $line = ['item' => $item, 'carrying_ore' => null, 'tax_value_ore' => null, 'difference_ore' => null, 'nature' => null, 'gross_ore' => 0, 'liability_ore' => 0, 'asset_ore' => 0, 'equity_ore' => 0, 'rows' => []];

        if ($item->kind !== K3TransitionItem::DEFERRED_TAX) {
            $amount = $item->amount_ore->ore ?? 0;
            $line['equity_ore'] = $amount;
            $line['rows'] = [
                ['account' => $equityAccount, 'amount' => -$amount, 'description' => $item->description],
                ['account' => (int) $item->account, 'amount' => $amount, 'description' => $item->description],
            ];

            return $line;
        }

        $kind = (string) $item->deferred_tax_kind;
        $carrying = self::carryingOf($item, $opening);
        $line['carrying_ore'] = $carrying;

        if ($item->relief_35_27) {
            $line['nature'] = self::RELIEF;

            return $line;
        }

        $taxValue = $item->tax_value_ore->ore ?? 0;
        $difference = $carrying - $taxValue;
        $nature = DeferredTaxProposal::nature($kind, $carrying, $taxValue);
        $gross = $rate === null ? 0 : DeferredTaxProposal::deferredTax($difference, $rate);
        $liability = $nature === self::TAXABLE ? $gross : 0;
        $asset = $nature === self::DEDUCTIBLE ? min($gross, $item->recognised_ore->ore) : 0;

        $line['tax_value_ore'] = $taxValue;
        $line['difference_ore'] = $difference;
        $line['nature'] = $nature;
        $line['gross_ore'] = $gross;
        $line['liability_ore'] = $liability;
        $line['asset_ore'] = $asset;
        $line['equity_ore'] = $asset - $liability;

        if ($liability > 0) {
            $line['rows'] = [
                ['account' => $equityAccount, 'amount' => $liability, 'description' => $item->description],
                ['account' => K3Rules::int('deferred_tax.liability_account'), 'amount' => -$liability, 'description' => $item->description],
            ];
        }

        if ($asset > 0) {
            $line['rows'] = [
                ['account' => K3Rules::int('deferred_tax.asset_account'), 'amount' => $asset, 'description' => $item->description],
                ['account' => $equityAccount, 'amount' => -$asset, 'description' => $item->description],
            ];
        }

        return $line;
    }

    /**
     * The carrying amount at the transition date: the opening balances of the
     * accounts (a liability credit positive), the entered amount, or 0 for a
     * loss carry-forward.
     *
     * @param  array<int, int>  $opening
     */
    private static function carryingOf(K3TransitionItem $item, array $opening): int
    {
        if ($item->deferred_tax_kind === 'LOSS_CARRY_FORWARD') {
            return 0;
        }

        if ($item->account_from !== null && $item->account_to !== null) {
            $sum = 0;

            foreach ($opening as $number => $ore) {
                if ($number >= $item->account_from && $number <= $item->account_to) {
                    $sum += $ore;
                }
            }

            return $item->deferred_tax_kind === 'OTHER_LIABILITY' ? -$sum : $sum;
        }

        return $item->carrying_ore->ore ?? 0;
    }

    /**
     * @return list<string>
     */
    private static function policyBlockers(K3Policies $policies, ?K3Transition $earlier, bool $forced): array
    {
        $blockers = [];
        $missing = array_filter(
            [K3PolicyKey::ComparativesRestatedAtTransition, K3PolicyKey::K3AppliedBefore, K3PolicyKey::LeftK2Reason],
            fn (K3PolicyKey $key): bool => $policies->value($key) === null,
        );

        if ($missing !== []) {
            $blockers[] = __('Svara på frågorna om övergången under Bokslut → Regelverk först: :questions', ['questions' => implode(' ', array_map(fn (K3PolicyKey $key): string => $key->question(), $missing))]);
        }

        if ($policies->value(K3PolicyKey::ComparativesRestatedAtTransition) === K3YesNo::Yes) {
            $blockers[] = __('Omräknade jämförelsetal vid övergången stöds inte ännu i NONOX. Ett mindre företag får låta bli att räkna om dem och i stället upplysa om den bristande jämförbarheten (K3 punkt 35.7, årsredovisningslagen 3 kap. 5 § fjärde stycket).');
        }

        if ($policies->chapter35Allowed() === false) {
            $blockers[] = __('Företaget har tillämpat K3 tidigare och lämnar K2 av eget val. Kapitel 35 får då inte tillämpas en gång till (K3 punkt 35.1).');
        }

        if ($earlier !== null && ! $forced) {
            $blockers[] = __('En övergång till K3 är redan bokförd för räkenskapsåret som börjar :date. Kapitel 35 får bara tillämpas en gång (K3 punkt 35.1), utom för ett företag som inte längre får tillämpa K2 enligt BFNAR 2025:2.', ['date' => $earlier->transition_date->toDateString()]);
        }

        return $blockers;
    }

    /**
     * @return list<string>
     */
    private static function ledgerBlockers(Company $company, FiscalYear $fiscalYear, ?FiscalYear $previous): array
    {
        $blockers = [];

        if (! $fiscalYear->isOpen()) {
            $blockers[] = __('Räkenskapsåret :year är stängt.', ['year' => $fiscalYear->label()]);
        }

        if ($company->locked_until_date !== null && $fiscalYear->start_date->lessThanOrEqualTo($company->locked_until_date)) {
            $blockers[] = __('Bokföringen är låst till och med :date. Övergången bokförs på räkenskapsårets första dag, :start, så lås upp den perioden först.', ['date' => $company->locked_until_date->toDateString(), 'start' => $fiscalYear->start_date->toDateString()]);
        }

        if ($previous === null) {
            return $blockers;
        }

        if ($previous->status !== FiscalYearStatus::Closed) {
            $blockers[] = __('Stäng räkenskapsåret :previous först. Övergången utgår från dess utgående balanser, och de får inte kunna ändras efteråt.', ['previous' => $previous->label()]);
        }

        if (OpeningBalance::query()->where('fiscal_year_id', $fiscalYear->id)->doesntExist()) {
            $blockers[] = __('Räkenskapsåret :year har inga ingående balanser. För över balanserna från :previous först.', ['year' => $fiscalYear->label(), 'previous' => $previous->label()]);
        }

        $stale = StaleOpeningBalances::computeForYear($company, $fiscalYear);

        if ($stale !== null) {
            $blockers[] = __('De ingående balanserna för :year stämmer inte med de utgående balanserna för :previous (konto :accounts). Uppdatera dem först, så att övergången utgår från K2-årets utgående balanser.', ['year' => $fiscalYear->label(), 'previous' => $previous->label(), 'accounts' => implode(', ', array_column($stale['accounts'], 'number'))]);
        }

        return $blockers;
    }

    /**
     * The buildings of the register that exist at the transition date, each
     * with its state: split on the transition date (the 35.18 allocation),
     * split later, or not split.
     *
     * @return list<array{asset: FixedAsset, status: string, carrying_before_ore: int, carrying_after_ore: int, split_on: ?CarbonImmutable}>
     */
    private static function buildings(CarbonImmutable $transitionDate): array
    {
        $assets = FixedAsset::query()
            ->whereNull('parent_id')
            ->where('category', AssetCategory::Buildings->value)
            ->whereDate('acquired_on', '<', $transitionDate->toDateString())
            ->where(fn ($query) => $query->where('status', '!=', FixedAsset::DISPOSED)->orWhereDate('disposed_on', '>=', $transitionDate->toDateString()))
            ->with('components')
            ->withSum('depreciations', 'amount_ore')
            ->orderBy('number')
            ->get();

        $buildings = [];

        foreach ($assets as $asset) {
            $before = $asset->acquisition_ore->ore - $asset->accumulatedOre();

            if (! $asset->isSplit()) {
                $buildings[] = ['asset' => $asset, 'status' => 'not_split', 'carrying_before_ore' => $before, 'carrying_after_ore' => $before, 'split_on' => null];

                continue;
            }

            $after = 0;
            $splitOn = null;

            foreach ($asset->components as $component) {
                $after += $component->acquisition_ore->ore - $component->opening_accumulated_ore->ore;
                $splitOn = $splitOn === null || $component->depreciation_start->lessThan($splitOn) ? $component->depreciation_start : $splitOn;
            }

            $expected = $asset->depreciation_start->greaterThan($transitionDate) ? $asset->depreciation_start : $transitionDate;
            $status = $splitOn !== null && $splitOn->equalTo($expected) ? 'split' : 'split_later';
            $buildings[] = ['asset' => $asset, 'status' => $status, 'carrying_before_ore' => $before, 'carrying_after_ore' => $after, 'split_on' => $splitOn];
        }

        return $buildings;
    }

    /** @param  array{asset: FixedAsset, status: string, carrying_before_ore: int, carrying_after_ore: int, split_on: ?CarbonImmutable}  $building */
    private static function buildingBlocker(array $building, CarbonImmutable $transitionDate): ?string
    {
        return match ($building['status']) {
            'not_split' => (string) __('Byggnaden :number :name i anläggningsregistret är inte uppdelad i komponenter. Dela upp den per övergångsdagen :date under Anläggningar innan övergången bokförs (K3 punkt 35.18).', ['number' => $building['asset']->number, 'name' => $building['asset']->name, 'date' => $transitionDate->toDateString()]),
            'split_later' => (string) __('Byggnaden :number :name är uppdelad från :split. Vid övergången ska det redovisade värdet delas upp per övergångsdagen :date (K3 punkt 35.18).', ['number' => $building['asset']->number, 'name' => $building['asset']->name, 'split' => $building['split_on']?->toDateString(), 'date' => $transitionDate->toDateString()]),
            default => null,
        };
    }

    /** @return ?array{key: string, label: string, section: string, ranges: list<array{0: int, 1: int}>, source: string} */
    private static function lineOf(int $number): ?array
    {
        foreach (K3Schedule::BALANCE as $line) {
            if (self::inRanges($number, $line['ranges'])) {
                return $line;
            }
        }

        return null;
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
}
