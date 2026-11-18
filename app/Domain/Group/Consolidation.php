<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\Accounting\Reports\LedgerQuery;
use App\Domain\Accounting\Reports\LedgerReports;
use App\Domain\Accounting\VoucherStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyHolding;
use App\Models\CorporateGroup;
use App\Models\FiscalYear;
use App\Models\GroupAccountMap;
use App\Models\IntercompanyRowTag;
use App\Models\Voucher;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The consolidation engine (ADR-060, addendum 2026-11-07, 2.4; ADR-081 E24,
 * S2-1.4). Reads each included company's posted ledger inside
 * CurrentCompany::run() with LedgerQuery, applies the group's account map,
 * cuts a subsidiary's income and expenses to the days it was a member, and
 * sums. It returns what it read (inputs) and what it gave (output); the same
 * inputs always give the same output. It never writes anything, and it never
 * writes into any company's books: stage 1 has no accepted eliminations yet
 * (S2-1.5 adds them to the inputs as group entry ids), so the output is the
 * gross sum after the account map.
 *
 * The output holds consolidated figures only, never one company's: it is
 * what a CONSOLIDATED viewer sees. What was read per company stays in the
 * inputs, which only FULL access shows.
 *
 * Which companies are read is GroupAccess::includedCompanyIds(), the same
 * rule that decides FULL access, so access and figures never differ. The
 * number of queries is fixed per company, whatever the size of its ledger.
 */
final class Consolidation
{
    /** Bumped when the engine's arithmetic changes, so an old run's hashes are not compared with a new engine's. */
    public const int ENGINE_VERSION = 1;

    /** Bumped when the label below changes (ADR-060 2.4, S2-R14, H5). */
    public const int LABEL_VERSION = 1;

    public const string LABEL = 'Intern koncernrapport, inte en koncernredovisning enligt ÅRL';

    /** Why a company of the group adds nothing to the run (inputs only). */
    public const string EXCLUDED_NO_FISCAL_YEAR = 'NO_FISCAL_YEAR';

    public const string EXCLUDED_NOT_MEMBER = 'NOT_MEMBER_IN_PERIOD';

    /** Accounts 1000–2999 are the balance sheet, 3000–8999 the income statement; 9xxx is internal and never consolidated. */
    private const int FIRST_INCOME_ACCOUNT = 3000;

    private const int LAST_ACCOUNT = 8999;

    private const int FIRST_ACCOUNT = 1000;

    /**
     * @return array{inputs: array<string, mixed>, output: array<string, mixed>, preliminary: bool}
     *
     * @throws ValidationException when the group cannot be consolidated for the period (blocked)
     */
    public function compute(GroupAccess $access, CorporateGroup $group, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $access->includedCompanyIds($group, $from);
        sort($ids);
        // The parent first, then by id: the order decides account names, so it must never vary.
        $ids = array_values(array_unique([$group->parent_company_id, ...$ids]));

        /** @var Collection<int, Company> $companies */
        $companies = Company::query()->whereIn('id', $ids)->get()->keyBy('id');
        $parent = $companies->get($group->parent_company_id) ?? throw ValidationException::withMessages(['group' => __('Moderföretaget finns inte längre.')]);

        $this->refuseOtherCurrencies($companies, $parent);

        $members = $group->relationLoaded('members') ? $group->members : $group->members()->get();
        $holdingIds = $members->pluck('holding_id', 'company_id')->filter()->map(fn (mixed $id): int => (int) $id)->all();
        $holdings = $this->holdings(array_values($holdingIds));
        $this->refuseVotesOverAll($ids, $to);

        $read = [];
        $excluded = [];

        foreach ($ids as $companyId) {
            $company = $companies->get($companyId);

            if (! $company instanceof Company) {
                continue;
            }

            $holding = isset($holdingIds[$companyId]) ? ($holdings[$holdingIds[$companyId]] ?? null) : null;
            $window = $companyId === $parent->id ? ['from' => $from, 'to' => $to, 'balance' => true] : $this->window($holding, $from, $to);

            if ($window === null) {
                $excluded[] = ['company_id' => $companyId, 'reason' => self::EXCLUDED_NOT_MEMBER];

                continue;
            }

            $result = CurrentCompany::run($company, fn (): ?array => $this->read($company, $group, $from, $to, $window));

            if ($result === null) {
                if ($companyId === $parent->id) {
                    throw ValidationException::withMessages(['period' => __('Moderföretaget har inget räkenskapsår som omfattar hela perioden. Välj en period inom ett av dess räkenskapsår.')]);
                }

                $excluded[] = ['company_id' => $companyId, 'reason' => self::EXCLUDED_NO_FISCAL_YEAR];

                continue;
            }

            $read[] = ['company' => $company, 'role' => $companyId === $parent->id ? 'parent' : 'subsidiary', ...$result];
        }

        $inputs = $this->inputs($group, $from, $to, $read, $excluded);
        $output = $this->output($from, $to, $read);

        return [
            'inputs' => $inputs,
            'output' => $output,
            'preliminary' => (bool) $inputs['preliminary'],
        ];
    }

    /**
     * SHA-256 of the canonical JSON of a value: keys sorted at every level,
     * so a value read back from the database (jsonb reorders keys) gives the
     * same hash as the one that was written.
     *
     * @param  array<array-key, mixed>  $value
     */
    public static function hash(array $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    /** @var array<int, string>|null */
    private static ?array $basNames = null;

    /**
     * The standard names of the BAS accounts NONOX ships (database/data/bas/2026), K3 first.
     *
     * @return array<int, string>
     */
    public static function basNames(): array
    {
        if (self::$basNames !== null) {
            return self::$basNames;
        }

        $names = [];

        foreach (['k3', 'k2', 'k1'] as $framework) {
            $path = database_path("data/bas/2026/{$framework}.json");
            $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

            foreach (is_array($data) ? ($data['accounts'] ?? []) : [] as $account) {
                $names[(int) $account['number']] ??= (string) $account['name'];
            }
        }

        return self::$basNames = $names;
    }

    /** @param  array<array-key, mixed>  $value */
    public static function canonicalJson(array $value): string
    {
        return json_encode(self::canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }

    /**
     * Foreign currency is out of scope (S2-R10): every company must keep its
     * books in the parent's currency. Checked when a member is confirmed, and
     * again here because a company's currency can change afterwards.
     *
     * @param  Collection<int, Company>  $companies
     */
    private function refuseOtherCurrencies(Collection $companies, Company $parent): void
    {
        if ($companies->contains(fn (Company $company): bool => $company->base_currency !== $parent->base_currency)) {
            throw ValidationException::withMessages(['group' => __('Ett företag i koncernen har en annan redovisningsvaluta än moderföretaget. Konsolidering i flera valutor stöds inte.')]);
        }
    }

    /**
     * The acquisition and disposal dates of the holdings that made the
     * companies members, keyed by holding id.
     *
     * @param  list<int>  $holdingIds
     * @return array<int, array{acquired_on: CarbonImmutable, disposed_on: ?CarbonImmutable}>
     */
    private function holdings(array $holdingIds): array
    {
        if ($holdingIds === []) {
            return [];
        }

        // Across the members on purpose: only the two dates of the holdings that ground each membership are
        // read, to cut each subsidiary's figures to the days it belonged to the group (ADR-060 2.4). Nothing
        // else of a holding reaches the run or the page; the caller has already checked the user's group access.
        $rows = CompanyScope::bypass(fn (): Collection => CompanyHolding::query()
            ->whereIn('id', $holdingIds)
            ->get(['id', 'acquired_on', 'disposed_on']));

        $dates = [];

        foreach ($rows as $row) {
            $dates[$row->id] = ['acquired_on' => $row->acquired_on, 'disposed_on' => $row->disposed_on];
        }

        return $dates;
    }

    /**
     * ADR-060 S2-1.1 acceptance criterion: a group whose active holdings in
     * one organisation number exceed 100 % of the votes is blocked until
     * corrected, because RecordHolding cannot compare other members'
     * holdings for every user (no vote oracle).
     *
     * @param  list<int>  $ids
     */
    private function refuseVotesOverAll(array $ids, CarbonImmutable $to): void
    {
        // Across the included members on purpose: only the votes per organisation number are summed, and only
        // whether any sum exceeds 100 % is used. No holding, share or name reaches the run or the page.
        $over = CompanyScope::bypass(fn (): bool => CompanyHolding::query()
            ->whereIn('company_id', $ids)
            ->where('acquired_on', '<=', $to->toDateString())
            ->activeOn($to)
            ->groupBy('held_org_number')
            ->havingRaw('SUM(voting_bp) > ?', [GroupHoldings::ALL_VOTES_BP])
            ->toBase()
            ->select('held_org_number')
            ->exists());

        if ($over) {
            throw ValidationException::withMessages(['group' => __('Företagen i koncernen har tillsammans registrerat mer än 100 % av rösterna i samma företag. Rätta innehaven under Koncernstruktur innan koncernen kan konsolideras.')]);
        }
    }

    /**
     * The days a subsidiary belonged to the group within the period: income
     * and expenses from the acquisition day to the day before the disposal
     * ("active" = disposed_on empty or after the day), the balance sheet when
     * it is a member on the last day. Null when it belonged on none of them.
     *
     * @param  ?array{acquired_on: CarbonImmutable, disposed_on: ?CarbonImmutable}  $holding
     * @return ?array{from: ?CarbonImmutable, to: ?CarbonImmutable, balance: bool}
     */
    private function window(?array $holding, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        if ($holding === null) {
            // A member without a grounding holding cannot be dated: it is not consolidated rather than guessed.
            return null;
        }

        $start = $holding['acquired_on']->max($from);
        $end = $holding['disposed_on'] === null ? $to : $holding['disposed_on']->subDay()->min($to);
        $balance = $holding['acquired_on']->lessThanOrEqualTo($to) && ($holding['disposed_on'] === null || $holding['disposed_on']->greaterThan($to));

        if ($start->greaterThan($end) && ! $balance) {
            return null;
        }

        return $start->greaterThan($end)
            ? ['from' => null, 'to' => null, 'balance' => $balance]
            : ['from' => $start, 'to' => $end, 'balance' => $balance];
    }

    /**
     * One company, inside CurrentCompany::run(): its fiscal year covering
     * the period, the balances at the end of the period, the movements of
     * its days in the group, and the facts the run's inputs hash. A fixed
     * number of queries. Null when no fiscal year covers the whole period.
     *
     * @param  array{from: ?CarbonImmutable, to: ?CarbonImmutable, balance: bool}  $window
     * @return ?array<string, mixed>
     */
    private function read(Company $company, CorporateGroup $group, CarbonImmutable $from, CarbonImmutable $to, array $window): ?array
    {
        // The year must cover the company's own days in the group, not the whole period: a subsidiary in its
        // first, shorter year (acquired or formed during the period) is read from that year.
        $needFrom = $window['from'] ?? $to;
        $needTo = $window['balance'] ? $to : ($window['to'] ?? $to);
        $year = FiscalYear::query()
            ->where('start_date', '<=', $needFrom->toDateString())
            ->where('end_date', '>=', $needTo->toDateString())
            ->first();

        if (! $year instanceof FiscalYear) {
            return null;
        }

        $accounts = Account::query()->orderBy('id')->get(['id', 'number', 'name']);
        $numbers = $accounts->pluck('number', 'id')->map(fn (mixed $number): int => (int) $number)->all();
        $names = $accounts->pluck('name', 'number')->map(fn (mixed $name): string => (string) $name)->all();
        $map = GroupAccountMap::query()->where('corporate_group_id', $group->id)->orderBy('account_number')->get(['account_number', 'group_account_number']);
        $mapped = $map->pluck('group_account_number', 'account_number')->map(fn (mixed $number): int => (int) $number)->all();

        $balances = [];
        $openingHash = null;

        if ($window['balance']) {
            $query = new LedgerQuery($year, $year->start_date, $to);
            $opening = $query->openingBalances();
            $openingHash = self::hash($opening->sortKeys()->map(fn (mixed $amount): int => (int) $amount)->all());

            foreach ($query->movements() as $accountId => $movement) {
                $opening[$accountId] = (int) ($opening[$accountId] ?? 0) + $movement['debit'] - $movement['credit'];
            }

            foreach ($opening as $accountId => $amount) {
                $number = $numbers[$accountId] ?? null;

                if ($number !== null && $number < self::FIRST_INCOME_ACCOUNT && $number >= self::FIRST_ACCOUNT && (int) $amount !== 0) {
                    $balances[$number] = (int) $amount;
                }
            }
        }

        $income = [];

        if ($window['from'] !== null && $window['to'] !== null) {
            foreach ((new LedgerQuery($year, $window['from'], $window['to']))->movements() as $accountId => $movement) {
                $number = $numbers[$accountId] ?? null;

                if ($number !== null && $number >= self::FIRST_INCOME_ACCOUNT && $number <= self::LAST_ACCOUNT && $movement['debit'] !== $movement['credit']) {
                    // Signed as the ledger: debit positive. Presented later as income positive.
                    $income[$number] = $movement['debit'] - $movement['credit'];
                }
            }
        }

        $vouchers = Voucher::query()
            ->where('fiscal_year_id', $year->id)
            ->where('status', VoucherStatus::Posted->value)
            ->toBase()
            ->selectRaw('COUNT(*) AS posted')
            ->selectRaw('(SELECT v.hash FROM vouchers v WHERE v.company_id = ? AND v.fiscal_year_id = ? AND v.status = ? ORDER BY v.posted_at DESC, v.id DESC LIMIT 1) AS last_hash', [$company->id, $year->id, VoucherStatus::Posted->value])
            ->first();

        // The tag set of the year, hashed in the database so no tag is loaded (ADR-060 2.4: a changed tag is a changed input).
        $tags = IntercompanyRowTag::query()
            ->join('voucher_rows', 'voucher_rows.id', '=', 'intercompany_row_tags.voucher_row_id')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->where('vouchers.fiscal_year_id', $year->id)
            ->toBase()
            ->selectRaw("encode(sha256(convert_to(COALESCE(string_agg(intercompany_row_tags.voucher_row_id::text || ':' || intercompany_row_tags.counterparty_org_number || ':' || intercompany_row_tags.kind, ',' ORDER BY intercompany_row_tags.voucher_row_id), ''), 'UTF8')), 'hex') AS tags_hash")
            ->selectRaw('COUNT(*) AS tags')
            ->first();

        $locked = $company->locked_until_date !== null && $company->locked_until_date->greaterThanOrEqualTo($to);
        $closed = $year->status === FiscalYearStatus::Closed;

        return [
            'fiscal_year' => $year,
            'window' => $window,
            'balances' => $balances,
            'income' => $income,
            'map' => $mapped,
            'names' => $names,
            'facts' => [
                'fiscal_year_id' => $year->id,
                'fiscal_year_closed' => $closed,
                'locked_until' => $company->locked_until_date?->toDateString(),
                'preliminary' => ! $closed && ! $locked,
                'posted_vouchers' => (int) ($vouchers->posted ?? 0),
                'last_voucher_hash' => $vouchers->last_hash ?? null,
                'opening_hash' => $openingHash,
                'accounts_hash' => self::hash($accounts->map(fn (Account $account): array => [(int) $account->id, (int) $account->number, (string) $account->name])->all()),
                'tags' => (int) ($tags->tags ?? 0),
                'tags_hash' => (string) ($tags->tags_hash ?? ''),
                'map_hash' => self::hash($map->map(fn (GroupAccountMap $row): array => [$row->account_number, $row->group_account_number])->all()),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $read
     * @param  list<array{company_id: int, reason: string}>  $excluded
     * @return array<string, mixed>
     */
    private function inputs(CorporateGroup $group, CarbonImmutable $from, CarbonImmutable $to, array $read, array $excluded): array
    {
        $companies = array_map(fn (array $row): array => [
            'company_id' => $row['company']->id,
            'role' => $row['role'],
            'income_from' => $row['window']['from']?->toDateString(),
            'income_to' => $row['window']['to']?->toDateString(),
            'balance' => $row['window']['balance'],
            ...$row['facts'],
        ], $read);

        return [
            'engine_version' => self::ENGINE_VERSION,
            'label_version' => self::LABEL_VERSION,
            'corporate_group_id' => $group->id,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'companies' => $companies,
            'excluded' => $excluded,
            'tags_hash' => self::hash(array_map(fn (array $company): array => [$company['company_id'], $company['tags_hash']], $companies)),
            'map_hash' => self::hash(array_map(fn (array $company): array => [$company['company_id'], $company['map_hash']], $companies)),
            // Accepted eliminations from the group ledger (S2-1.5, ADR-081 E25). None exist in stage 1 S2-1.4.
            'group_entries' => [],
            // The BAS standard names the output may use for accounts the parent lacks: a new BAS file is a changed input.
            'bas_names_hash' => self::hash(self::basNames()),
            'preliminary' => array_any($companies, fn (array $company): bool => $company['preliminary']),
        ];
    }

    /**
     * The consolidated statements: the companies' amounts after the account
     * map, summed per group account. Consolidated figures only.
     *
     * @param  list<array<string, mixed>>  $read
     * @return array<string, mixed>
     */
    private function output(CarbonImmutable $from, CarbonImmutable $to, array $read): array
    {
        $balances = [];
        $income = [];

        foreach ($read as $row) {
            foreach ($row['balances'] as $number => $amount) {
                $groupNumber = $row['map'][$number] ?? $number;
                $balances[$groupNumber] = ($balances[$groupNumber] ?? 0) + $amount;
            }

            foreach ($row['income'] as $number => $amount) {
                $groupNumber = $row['map'][$number] ?? $number;
                $income[$groupNumber] = ($income[$groupNumber] ?? 0) + $amount;
            }
        }

        ksort($balances);
        ksort($income);

        // A group account is named from the parent's chart, else with the BAS standard name, else by number only:
        // never with a subsidiary's own free text, which a consolidated viewer may not read.
        $parentNames = collect($read)->firstWhere('role', 'parent')['names'] ?? [];
        $names = [];

        foreach (array_keys($balances + $income) as $number) {
            $names[$number] = $parentNames[$number] ?? self::basNames()[$number] ?? '';
        }

        $statement = $this->incomeStatement($income, $names);
        $balance = $this->balanceSheet($balances, $names);

        return [
            'label_version' => self::LABEL_VERSION,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'income' => $statement,
            'balance' => $balance,
            // The year's result in the balance sheet that the income statement above does not cover: before the
            // period started, or of a subsidiary before it was acquired (that part needs an acquisition analysis, stage 2).
            'result_outside_period' => $balance['unbooked_result'] - $statement['result'],
            'companies' => [
                'included' => count($read),
                'with_balance' => count(array_filter($read, fn (array $row): bool => $row['window']['balance'])),
                'cut' => count(array_filter($read, fn (array $row): bool => $row['role'] !== 'parent'
                    && ($row['window']['from']?->toDateString() !== $from->toDateString() || $row['window']['to']?->toDateString() !== $to->toDateString() || ! $row['window']['balance']))),
            ],
        ];
    }

    /**
     * @param  array<int, int>  $amounts  debit positive, per group account
     * @param  array<int, string>  $names
     * @return array{groups: list<array{key: string, rows: list<array{number: int, name: string, amount: int}>, total: int, subtotal: ?int}>, result: int}
     */
    private function incomeStatement(array $amounts, array $names): array
    {
        $groups = [];
        $totals = [];

        foreach (LedgerReports::INCOME_GROUPS as $group) {
            $rows = [];

            foreach ($amounts as $number => $amount) {
                if ($number >= $group['from'] && $number <= $group['to'] && $amount !== 0) {
                    $rows[] = ['number' => $number, 'name' => $names[$number] ?? '', 'amount' => -$amount];
                }
            }

            $totals[$group['key']] = array_sum(array_column($rows, 'amount'));
            $subtotal = LedgerReports::INCOME_SUBTOTALS[$group['key']] ?? null;

            $groups[] = [
                'key' => $group['key'],
                'rows' => $rows,
                'total' => $totals[$group['key']],
                'subtotal' => $subtotal === null ? null : array_sum(array_intersect_key($totals, array_flip($subtotal['groups']))),
            ];
        }

        return ['groups' => $groups, 'result' => array_sum($totals)];
    }

    /**
     * @param  array<int, int>  $amounts  debit positive, per group account
     * @param  array<int, string>  $names
     * @return array{assets: array{groups: list<array<string, mixed>>, total: int}, equity: array{groups: list<array<string, mixed>>, total: int}, unbooked_result: int}
     */
    private function balanceSheet(array $amounts, array $names): array
    {
        $sides = ['assets' => ['groups' => [], 'total' => 0], 'equity' => ['groups' => [], 'total' => 0]];

        foreach (LedgerReports::BALANCE_GROUPS as $group) {
            $sign = $group['side'] === 'assets' ? 1 : -1;
            $rows = [];

            foreach ($amounts as $number => $amount) {
                if ($number >= $group['from'] && $number <= $group['to'] && $amount !== 0) {
                    $rows[] = ['number' => $number, 'name' => $names[$number] ?? '', 'amount' => $sign * $amount];
                }
            }

            $total = array_sum(array_column($rows, 'amount'));
            $sides[$group['side']]['groups'][] = ['key' => $group['key'], 'rows' => $rows, 'total' => $total];
            $sides[$group['side']]['total'] += $total;
        }

        // The result not yet booked to equity makes the two sides meet, as in the company's own balance sheet.
        $unbooked = $sides['assets']['total'] - $sides['equity']['total'];
        $sides['equity']['total'] += $unbooked;

        return [...$sides, 'unbooked_result' => $unbooked];
    }
}
