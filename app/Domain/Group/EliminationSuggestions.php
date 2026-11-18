<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Domain\Accounting\VoucherStatus;
use App\Models\CorporateGroup;
use App\Models\FiscalYear;
use App\Models\GroupEntry;
use App\Models\IntercompanyRowTag;
use App\Models\OpeningBalance;
use App\Models\VoucherRow;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Elimination suggestions for a shared group and a consolidation period
 * (ADR-060, addendum 2026-11-07, 3.2, S2-1.5; ADR-081 E25). Computed, never
 * stored: a suggestion becomes a group ledger entry only when a person
 * accepts it (RecordGroupEntry::accept).
 *
 * Six pairs are matched from the intercompany row tags (S2-1.3): for each
 * ordered pair of companies X and Y, X's rows tagged with the pair's credit
 * kind and counterparty Y against Y's rows tagged with the debit kind and
 * counterparty X. Income statement accounts (3000–8999) count over the
 * company's days in the group; balance sheet accounts (1000–2999) are
 * closing balances on the last day, summed over every tagged row in any
 * fiscal year, when its balance sheet is taken. The first fiscal year's
 * opening balances carry no tags: a side with such an opening balance on a
 * balance account is not matched but "Kräver granskning".
 *
 * Shares against equity comes from the balances: a holder's accounts
 * 1310–1318 (andelar i koncernföretag) against the share capital (2081) of
 * the member it holds. It can be accepted only for one wholly owned member
 * the holder has owned since its first fiscal year, with equal sides; all
 * else needs an acquisition analysis (stage 2).
 *
 * The same companies, days and fiscal years as the run, through
 * Consolidation::gather(). Each suggestion has a fingerprint over what it
 * would write; acceptance recomputes it and refuses when it differs from the
 * one the person saw. Shown with FULL access only: suggestions are
 * per-company figures.
 */
final class EliminationSuggestions
{
    public const string MATCHED = 'MATCHED';

    public const string MISMATCH = 'MISMATCH';

    public const string NEEDS_ACQUISITION_ANALYSIS = 'NEEDS_ACQUISITION_ANALYSIS';

    /** A balance account on a side has an opening balance no tagged row explains. */
    public const string NEEDS_REVIEW = 'NEEDS_REVIEW';

    /** Both sides net to zero: nothing to eliminate. */
    public const string NOTHING = 'NOTHING';

    /** Andelar i koncernföretag in BAS: 1310–1318 (1318 the accumulated write-downs). */
    private const int FIRST_SHARES_ACCOUNT = 1310;

    private const int LAST_SHARES_ACCOUNT = 1318;

    /** Aktiekapital in BAS. */
    private const int SHARE_CAPITAL_ACCOUNT = 2081;

    private const int FIRST_INCOME_ACCOUNT = 3000;

    public function __construct(private readonly Consolidation $consolidation) {}

    /**
     * @return array{companies: list<int>, suggestions: list<array<string, mixed>>}
     *
     * @throws ValidationException when the group cannot be consolidated for the period
     */
    public function compute(GroupAccess $access, CorporateGroup $group, CarbonImmutable $from, CarbonImmutable $to): array
    {
        ['read' => $read] = $this->consolidation->gather($access, $group, $from, $to);

        $byOrg = [];
        $tagged = [];

        $books = [];

        foreach ($read as $row) {
            $company = $row['company'];
            $byOrg[self::digits((string) $company->org_number)] = $company->id;
            $books[$company->id] = CurrentCompany::run($company, fn (): array => $this->books($row['fiscal_year'], $row['window'], $to));
            $tagged[$company->id] = $books[$company->id]['sums'];
        }

        $maps = array_column(array_map(fn (array $row): array => ['id' => $row['company']->id, 'map' => $row['map']], $read), 'map', 'id');
        $suggestions = [];

        foreach (EliminationType::tagged() as $type) {
            foreach ($this->tagPairs($type, $tagged, $byOrg) as $pair) {
                // A balance account whose opening balance the posted rows do not explain (typically a first year's
                // opening balance from an earlier program) may hold intra-group amounts the tags cannot see.
                $unexplained = $this->unexplained($pair['credit'], $books[$pair['credit_company']]['opening'])
                    || $this->unexplained($pair['debit'], $books[$pair['debit_company']]['opening']);
                $suggestions[] = $this->suggestion($type, $pair['credit_company'], $pair['debit_company'], $pair['credit'], $pair['debit'], $maps, $from, $to, $unexplained ? self::NEEDS_REVIEW : null);
            }
        }

        foreach ($this->sharesAgainstEquity($read, $books, $maps, $from, $to) as $suggestion) {
            $suggestions[] = $suggestion;
        }

        return [
            'companies' => array_map(fn (array $row): int => $row['company']->id, $read),
            'suggestions' => $this->withStatus($group, $from, $to, $suggestions),
        ];
    }

    /**
     * Four queries inside the company: the tagged amounts per account,
     * counterparty and kind (debit positive), the start of the company's
     * first fiscal year, and which balance accounts have an opening balance
     * that the posted rows do not explain.
     *
     * Income accounts count over the company's days in the group in the
     * year read. Balance accounts are closing balances: every tagged posted
     * row up to the last day, in any fiscal year, when the company's balance
     * sheet is taken (cold review E25, P1-1). The closing balance is the
     * year's opening balance plus the year's rows; the tags see only rows.
     * So an account whose opening balance in the year read differs from the
     * sum of all its posted rows before that year (a first year's opening
     * balance from an earlier program, or one entered by hand) holds amounts
     * no tag can explain.
     *
     * @param  array{from: ?CarbonImmutable, to: ?CarbonImmutable, balance: bool}  $window
     * @return array{sums: list<array{account: int, org: string, kind: string, amount: int}>, opening: array<int, true>, first_start: ?CarbonImmutable, started_here: bool}
     */
    private function books(FiscalYear $year, array $window, CarbonImmutable $to): array
    {
        $first = FiscalYear::query()->orderBy('start_date')->first(['id', 'start_date']);
        $opening = OpeningBalance::query()
            ->join('accounts', 'accounts.id', '=', 'opening_balances.account_id')
            ->where('opening_balances.fiscal_year_id', $year->id)
            ->where('accounts.number', '<', self::FIRST_INCOME_ACCOUNT)
            ->toBase()
            ->groupBy('accounts.number')
            ->selectRaw('accounts.number, SUM(opening_balances.amount_ore) AS amount')
            ->pluck('amount', 'number')
            ->map(fn (mixed $amount): int => (int) $amount)
            ->all();
        $earlier = VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->where('vouchers.voucher_date', '<', $year->start_date->toDateString())
            ->where('accounts.number', '<', self::FIRST_INCOME_ACCOUNT)
            ->toBase()
            ->groupBy('accounts.number')
            ->selectRaw('accounts.number, SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) AS amount')
            ->pluck('amount', 'number')
            ->map(fn (mixed $amount): int => (int) $amount)
            ->all();

        $unexplained = [];

        foreach (array_keys($opening + $earlier) as $account) {
            if (($opening[$account] ?? 0) !== ($earlier[$account] ?? 0)) {
                $unexplained[(int) $account] = true;
            }
        }

        // Books that started in NONOX have no opening balance in their first year; a company moved from another
        // program has one, and its history before NONOX (when it was bought, what equity it had) is unknown here.
        $startedHere = $first !== null && ! OpeningBalance::query()->where('fiscal_year_id', $first->id)->where('amount_ore', '<>', 0)->exists();

        return ['sums' => $this->tagSums($year, $window, $to), 'opening' => $unexplained, 'first_start' => $first?->start_date, 'started_here' => $startedHere];
    }

    /**
     * @param  array{from: ?CarbonImmutable, to: ?CarbonImmutable, balance: bool}  $window
     * @return list<array{account: int, org: string, kind: string, amount: int}>
     */
    private function tagSums(FiscalYear $year, array $window, CarbonImmutable $to): array
    {
        $income = $window['from'] !== null && $window['to'] !== null
            ? ['CASE WHEN accounts.number >= ? AND vouchers.fiscal_year_id = ? AND vouchers.voucher_date BETWEEN ? AND ? THEN voucher_rows.debit_ore - voucher_rows.credit_ore ELSE 0 END', [self::FIRST_INCOME_ACCOUNT, $year->id, $window['from']->toDateString(), $window['to']->toDateString()]]
            : ['0', []];
        $balance = $window['balance']
            ? ['CASE WHEN accounts.number < ? THEN voucher_rows.debit_ore - voucher_rows.credit_ore ELSE 0 END', [self::FIRST_INCOME_ACCOUNT]]
            : ['0', []];

        $rows = IntercompanyRowTag::query()
            ->join('voucher_rows', 'voucher_rows.id', '=', 'intercompany_row_tags.voucher_row_id')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->where('vouchers.voucher_date', '<=', $to->toDateString())
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereBetween('accounts.number', [1000, 8999])
            ->toBase()
            ->groupBy('accounts.number', 'intercompany_row_tags.counterparty_org_number', 'intercompany_row_tags.kind')
            ->select('accounts.number', 'intercompany_row_tags.counterparty_org_number', 'intercompany_row_tags.kind')
            ->selectRaw("SUM({$income[0]}) + SUM({$balance[0]}) AS amount", [...$income[1], ...$balance[1]])
            ->orderBy('accounts.number')
            ->get();

        $sums = [];

        foreach ($rows as $row) {
            if ((int) $row->amount !== 0) {
                $sums[] = ['account' => (int) $row->number, 'org' => self::digits((string) $row->counterparty_org_number), 'kind' => (string) $row->kind, 'amount' => (int) $row->amount];
            }
        }

        return $sums;
    }

    /**
     * @param  array<int, list<array{account: int, org: string, kind: string, amount: int}>>  $tagged
     * @param  array<string, int>  $byOrg
     * @return list<array{credit_company: int, debit_company: int, credit: array<int, int>, debit: array<int, int>}>
     */
    private function tagPairs(EliminationType $type, array $tagged, array $byOrg): array
    {
        $creditKind = $type->creditSide()?->value;
        $debitKind = $type->debitSide()?->value;
        $pairs = [];

        foreach ($tagged as $companyId => $rows) {
            foreach ($rows as $row) {
                $other = $byOrg[$row['org']] ?? null;

                if ($other === null || $other === $companyId) {
                    // A counterparty outside the companies the period reads is not a pair of this group and period.
                    continue;
                }

                if ($row['kind'] === $creditKind) {
                    $key = "{$companyId}:{$other}";
                    $pairs[$key] ??= ['credit_company' => $companyId, 'debit_company' => $other, 'credit' => [], 'debit' => []];
                    // The credit side counts credit positive.
                    $pairs[$key]['credit'][$row['account']] = ($pairs[$key]['credit'][$row['account']] ?? 0) - $row['amount'];
                }

                if ($row['kind'] === $debitKind) {
                    $key = "{$other}:{$companyId}";
                    $pairs[$key] ??= ['credit_company' => $other, 'debit_company' => $companyId, 'credit' => [], 'debit' => []];
                    $pairs[$key]['debit'][$row['account']] = ($pairs[$key]['debit'][$row['account']] ?? 0) + $row['amount'];
                }
            }
        }

        ksort($pairs);

        return array_values($pairs);
    }

    /**
     * @param  array<int, int>  $credit  account => amount on the credit side (credit positive)
     * @param  array<int, int>  $debit  account => amount on the debit side (debit positive)
     * @param  array<int, array<int, int>>  $maps
     * @return array<string, mixed>
     */
    private function suggestion(EliminationType $type, int $creditCompany, int $debitCompany, array $credit, array $debit, array $maps, CarbonImmutable $from, CarbonImmutable $to, ?string $status = null): array
    {
        ksort($credit);
        ksort($debit);
        $creditTotal = array_sum($credit);
        $debitTotal = array_sum($debit);

        $rows = [
            ...$this->rows($creditCompany, $credit, $maps, true),
            ...$this->rows($debitCompany, $debit, $maps, false),
        ];
        $total = array_sum(array_column($rows, 'debit_ore'));
        $status = match (true) {
            $creditTotal === 0 && $debitTotal === 0 => self::NOTHING,
            $status !== null => $status,
            $creditTotal === $debitTotal && $total > 0 => self::MATCHED,
            default => self::MISMATCH,
        };
        $key = "{$type->value}:{$creditCompany}:{$debitCompany}";

        return [
            'key' => $key,
            'type' => $type,
            'credit_company' => $creditCompany,
            'debit_company' => $debitCompany,
            'credit' => ['accounts' => $credit, 'total' => $creditTotal],
            'debit' => ['accounts' => $debit, 'total' => $debitTotal],
            'difference' => $creditTotal - $debitTotal,
            'status' => $status,
            'rows' => $status === self::MATCHED ? $rows : [],
            'total_ore' => $status === self::MATCHED ? $total : 0,
            'fingerprint' => Consolidation::hash([
                'key' => $key,
                'period' => [$from->toDateString(), $to->toDateString()],
                'credit' => $credit,
                'debit' => $debit,
                'rows' => $rows,
            ]),
        ];
    }

    /**
     * The entry rows of one side: the credit side's accounts are debited,
     * the debit side's credited; an account with the opposite sign moves to
     * the other column, so every row is one-sided.
     *
     * @param  array<int, int>  $amounts
     * @param  array<int, array<int, int>>  $maps
     * @return list<array{company_id: int, account_number: int, group_account_number: int, debit_ore: int, credit_ore: int}>
     */
    private function rows(int $companyId, array $amounts, array $maps, bool $debited): array
    {
        $rows = [];

        foreach ($amounts as $account => $amount) {
            if ($amount === 0) {
                continue;
            }

            $debit = $debited === ($amount > 0);
            $rows[] = [
                'company_id' => $companyId,
                'account_number' => $account,
                'group_account_number' => $maps[$companyId][$account] ?? $account,
                'debit_ore' => $debit ? abs($amount) : 0,
                'credit_ore' => $debit ? 0 : abs($amount),
            ];
        }

        return $rows;
    }

    /**
     * Whether a side uses a balance account with an unexplained opening balance.
     *
     * @param  array<int, int>  $accounts
     * @param  array<int, true>  $opening
     */
    private function unexplained(array $accounts, array $opening): bool
    {
        return array_any(array_keys($accounts), fn (int $account): bool => $account < self::FIRST_INCOME_ACCOUNT && isset($opening[$account]));
    }

    /**
     * Per holder: its shares in group companies against the share capital
     * of the members it holds, from the balances the run reads. MATCHED only
     * for one wholly owned member whose books started in NONOX (no opening
     * balance in its first year) and that the holder has owned since then
     * (it founded it; re-check P2-a): with several members the
     * holder's shares cannot be split per member, and a bought company's
     * equity at the acquisition is not its share capital (cold review E25,
     * P2-1). Everything else needs an acquisition analysis (stage 2).
     *
     * @param  list<array<string, mixed>>  $read
     * @param  array<int, array{sums: list<array<string, mixed>>, opening: array<int, true>, first_start: ?CarbonImmutable, started_here: bool}>  $books
     * @param  array<int, array<int, int>>  $maps
     * @return list<array<string, mixed>>
     */
    private function sharesAgainstEquity(array $read, array $books, array $maps, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byId = [];

        foreach ($read as $row) {
            $byId[$row['company']->id] = $row;
        }

        $suggestions = [];

        foreach ($byId as $holderId => $holder) {
            $held = array_filter($byId, fn (array $row): bool => $row['holder_company_id'] === $holderId && $row['window']['balance']);

            if ($held === [] || ! $holder['window']['balance']) {
                continue;
            }

            // The holder's shares, debit positive: the side that is credited when eliminated.
            $shares = array_filter($holder['balances'], fn (int $amount, int $account): bool => $account >= self::FIRST_SHARES_ACCOUNT && $account <= self::LAST_SHARES_ACCOUNT, ARRAY_FILTER_USE_BOTH);
            $equity = [];
            $whollyOwned = true;

            foreach ($held as $companyId => $row) {
                $whollyOwned = $whollyOwned && $row['capital_bp'] === GroupHoldings::ALL_VOTES_BP;
                $capital = -(int) ($row['balances'][self::SHARE_CAPITAL_ACCOUNT] ?? 0);

                if ($capital !== 0) {
                    $equity[$companyId] = $capital;
                }
            }

            if ($shares === [] && $equity === []) {
                continue;
            }

            ksort($equity);
            $founded = count($held) === 1 && array_all($held, function (array $row, int $companyId) use ($books): bool {
                $first = $books[$companyId]['first_start'] ?? null;

                // Founded here only: the member's books started in NONOX (no opening balance in its first year), and
                // the holder held it from that start. A company moved from another program may have been bought.
                return ($books[$companyId]['started_here'] ?? false)
                    && $row['acquired_on'] instanceof CarbonImmutable
                    && $first instanceof CarbonImmutable
                    && $row['acquired_on']->lessThanOrEqualTo($first);
            });
            $matched = $founded && $whollyOwned && array_sum($shares) === array_sum($equity) && array_sum($shares) > 0;
            $suggestion = $this->suggestion(EliminationType::SharesEquity, $holderId, $holderId, [], $shares, $maps, $from, $to, $matched ? self::MATCHED : self::NEEDS_ACQUISITION_ANALYSIS);

            // The credit side is the held members' share capital, one company each: rebuilt here because it spans companies.
            $equityRows = [];

            foreach ($equity as $companyId => $capital) {
                $equityRows = [...$equityRows, ...$this->rows($companyId, [self::SHARE_CAPITAL_ACCOUNT => $capital], $maps, true)];
            }

            $rows = $matched ? [...$equityRows, ...$suggestion['rows']] : [];
            $suggestion['key'] = EliminationType::SharesEquity->value.":{$holderId}";
            $suggestion['credit'] = ['accounts' => [self::SHARE_CAPITAL_ACCOUNT => array_sum($equity)], 'total' => array_sum($equity), 'companies' => $equity];
            $suggestion['difference'] = array_sum($equity) - array_sum($shares);
            $suggestion['wholly_owned'] = $whollyOwned;
            $suggestion['founded'] = $founded;
            $suggestion['status'] = $matched ? self::MATCHED : self::NEEDS_ACQUISITION_ANALYSIS;
            $suggestion['rows'] = $rows;
            $suggestion['total_ore'] = $matched ? array_sum(array_column($rows, 'debit_ore')) : 0;
            $suggestion['fingerprint'] = Consolidation::hash([
                'key' => $suggestion['key'],
                'period' => [$from->toDateString(), $to->toDateString()],
                'shares' => $shares,
                'equity' => $equity,
                'wholly_owned' => $whollyOwned,
                'founded' => $founded,
                'rows' => $rows,
            ]);
            $suggestions[] = $suggestion;
        }

        return $suggestions;
    }

    /**
     * Marks each suggestion with the entry that accepted it for this period
     * and has not been reversed, and whether the figures changed since.
     *
     * @param  list<array<string, mixed>>  $suggestions
     * @return list<array<string, mixed>>
     */
    private function withStatus(CorporateGroup $group, CarbonImmutable $from, CarbonImmutable $to, array $suggestions): array
    {
        $accepted = self::acceptedEntries($group, $from, $to);

        return array_map(function (array $suggestion) use ($accepted): array {
            $entry = $accepted[$suggestion['key']] ?? null;

            return [
                ...$suggestion,
                'accepted_entry' => $entry?->number,
                'accepted_entry_id' => $entry?->id,
                'changed_since_accepted' => $entry !== null && ($entry->source['fingerprint'] ?? null) !== $suggestion['fingerprint'],
            ];
        }, $suggestions);
    }

    /**
     * The accepted suggestions of the period that are not reversed, by suggestion key.
     *
     * @return array<string, GroupEntry>
     */
    public static function acceptedEntries(CorporateGroup $group, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return GroupEntry::query()
            ->where('corporate_group_id', $group->id)
            ->whereDate('period_from', $from->toDateString())
            ->whereDate('period_to', $to->toDateString())
            ->where('origin', GroupEntryOrigin::Suggestion->value)
            ->whereDoesntHave('reversedBy')
            ->orderBy('number')
            ->get(['id', 'number', 'suggestion_key', 'source'])
            ->keyBy('suggestion_key')
            ->all();
    }

    private static function digits(string $orgNumber): string
    {
        return (string) preg_replace('/\D/', '', $orgNumber);
    }
}
