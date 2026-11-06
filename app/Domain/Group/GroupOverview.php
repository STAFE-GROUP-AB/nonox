<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Domain\Accounting\Reports\LedgerQuery;
use App\Domain\Accounting\Reports\LedgerReports;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\CompanyGroupMark;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * The internal group overview (ADR-060): the income statement and balance
 * sheet of each company side by side with a sum column, and suggestions for
 * eliminating intra-group items. NOT a statutory koncernredovisning, and
 * nothing is booked anywhere.
 *
 * Every company is read inside CurrentCompany::run(), so the ordinary
 * company scope applies; no query crosses companies and nothing bypasses the
 * scope. Which companies are read is decided by GroupAccess. The number of
 * queries is fixed per company, whatever the number of accounts.
 */
final class GroupOverview
{
    /**
     * @return array{
     *     years: list<array{key: string, label: string, companies: int}>,
     *     year: ?string,
     *     till: ?CarbonImmutable,
     *     companies: list<array{id: int, name: string, org_number: ?string, ownership_bp: ?int}>,
     *     excluded: list<array{id: int, name: string, reason: string}>,
     *     hidden: int,
     *     income: array{groups: list<array<string, mixed>>, result: array{amounts: array<int, int>, sum: int}},
     *     balance: array{sides: array<string, array<string, mixed>>},
     *     marks: list<array{company_id: int, company: string, number: int, name: string, kind: MarkKind, amount: int}>,
     *     eliminations: list<array{key: string, label: string, left_label: string, right_label: string, left: int, right: int, eliminate: int, difference: int}>,
     *     adjusted: list<array{label: string, before: int, after: int}>
     * }
     */
    public function build(GroupAccess $access, CompanyGroup $group, ?string $yearKey = null, ?string $till = null): array
    {
        ['members' => $members, 'hidden' => $hidden] = $access->members($group);

        /** @var array<int, list<FiscalYear>> $yearsByCompany */
        $yearsByCompany = [];
        $counts = [];

        foreach ($members as $row) {
            $company = $row['company'];
            $years = CurrentCompany::run($company, fn () => FiscalYear::query()->orderByDesc('start_date')->get()->all());
            $yearsByCompany[$company->id] = $years;

            foreach ($years as $year) {
                $key = self::key($year);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        $options = $this->options($counts);
        $selected = collect($options)->firstWhere('key', $yearKey) ? $yearKey : $this->defaultKey($options);

        $included = [];
        $excluded = [];
        $periodEnd = null;
        $reports = [];

        foreach ($members as $row) {
            $company = $row['company'];
            $year = collect($yearsByCompany[$company->id])->first(fn (FiscalYear $year): bool => self::key($year) === $selected);

            if (! $year instanceof FiscalYear) {
                $excluded[] = ['id' => $company->id, 'name' => $company->name, 'reason' => $yearsByCompany[$company->id] === []
                    ? __('har inget räkenskapsår')
                    : __('har inte räkenskapsåret :year', ['year' => self::describe($selected)])];

                continue;
            }

            $periodEnd ??= self::until($year, $till);
            $included[] = ['member' => $row['member'], 'company' => $company];

            $reports[$company->id] = CurrentCompany::run($company, function () use ($year, $periodEnd): array {
                $query = new LedgerQuery($year, $year->start_date, $periodEnd);
                $ledger = new LedgerReports;

                return ['income' => $ledger->incomeStatement($query), 'balance' => $ledger->balanceSheet($query)];
            });
        }

        $companies = array_map(fn (array $row): array => [
            'id' => $row['company']->id,
            'name' => $row['company']->name,
            'org_number' => $row['company']->org_number,
            'ownership_bp' => $row['member']->ownership_bp,
        ], $included);

        $ids = array_column($companies, 'id');
        $income = $this->mergeIncome($ids, $reports);
        $balance = $this->mergeBalance($ids, $reports);
        $amounts = $this->amountsByNumber($ids, $reports);
        $marks = $this->marks($group, $companies, $amounts);

        return [
            'years' => array_map(fn (array $option): array => $option, $options),
            'year' => $selected,
            'till' => $periodEnd,
            'companies' => $companies,
            'excluded' => $excluded,
            'hidden' => $hidden,
            'income' => $income,
            'balance' => $balance,
            'marks' => $marks,
            ...$this->eliminate($marks, $income, $balance),
        ];
    }

    /**
     * The account label of a merged row: number and the first company's name, with a note when the companies name it differently.
     *
     * @param  array{number: int, name: string, name_differs: bool}  $row
     */
    public static function rowLabel(array $row): string
    {
        return "{$row['number']} {$row['name']}".($row['name_differs'] ? ' ('.__('namnet skiljer sig mellan företagen').')' : '');
    }

    public static function key(FiscalYear $year): string
    {
        return $year->start_date->toDateString().'_'.$year->end_date->toDateString();
    }

    public static function describe(?string $key): string
    {
        if ($key === null || ! str_contains($key, '_')) {
            return '';
        }

        [$from, $to] = explode('_', $key);

        return "{$from} – {$to}";
    }

    /** The chosen end of the period, kept inside the year; the whole year by default. */
    private static function until(FiscalYear $year, ?string $till): CarbonImmutable
    {
        if ($till === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $till)) {
            return $year->end_date;
        }

        try {
            $date = CarbonImmutable::parse($till)->startOfDay();
        } catch (\Throwable) {
            return $year->end_date;
        }

        return $date->max($year->start_date)->min($year->end_date);
    }

    /**
     * The year most of the companies have; the newest of those if several.
     *
     * @param  list<array{key: string, label: string, companies: int}>  $options
     */
    private function defaultKey(array $options): ?string
    {
        $best = null;

        foreach ($options as $option) {
            if ($best === null || $option['companies'] > $best['companies']) {
                $best = $option;
            }
        }

        return $best['key'] ?? null;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{key: string, label: string, companies: int}>
     */
    private function options(array $counts): array
    {
        $keys = array_keys($counts);
        rsort($keys);

        // Newest year first; the first option is the default.
        return array_map(fn (string $key): array => ['key' => $key, 'label' => self::describe($key), 'companies' => $counts[$key]], $keys);
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, array{income: array<string, mixed>, balance: array<string, mixed>}>  $reports
     * @return array{groups: list<array<string, mixed>>, result: array{amounts: array<int, int>, sum: int}}
     */
    private function mergeIncome(array $ids, array $reports): array
    {
        $groups = [];

        foreach ($ids as $id) {
            foreach ($reports[$id]['income']['groups'] as $index => $group) {
                $groups[$index] ??= ['key' => $group['key'], 'label' => $group['label'], 'rows' => [], 'totals' => [], 'subtotal' => null];
                $groups[$index]['totals'][$id] = $group['total'];

                foreach ($group['rows'] as $row) {
                    $groups[$index]['rows'][$row['number']] ??= ['number' => $row['number'], 'name' => $row['name'], 'name_differs' => false, 'amounts' => []];
                    $groups[$index]['rows'][$row['number']]['name_differs'] = $groups[$index]['rows'][$row['number']]['name_differs'] || $groups[$index]['rows'][$row['number']]['name'] !== $row['name'];
                    $groups[$index]['rows'][$row['number']]['amounts'][$id] = $row['amount'];
                }

                if ($group['subtotal'] !== null) {
                    $groups[$index]['subtotal'] ??= ['label' => $group['subtotal']['label'], 'amounts' => []];
                    $groups[$index]['subtotal']['amounts'][$id] = $group['subtotal']['amount'];
                }
            }
        }

        $result = [];

        foreach ($ids as $id) {
            $result[$id] = (int) $reports[$id]['income']['result'];
        }

        return [
            'groups' => array_values(array_map(fn (array $group): array => $this->finish($group, $ids), $groups)),
            'result' => ['amounts' => $result, 'sum' => array_sum($result)],
        ];
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, array{income: array<string, mixed>, balance: array<string, mixed>}>  $reports
     * @return array{sides: array<string, array<string, mixed>>}
     */
    private function mergeBalance(array $ids, array $reports): array
    {
        $sides = $this->emptySides();

        foreach ($ids as $id) {
            foreach ($reports[$id]['balance']['sides'] as $key => $side) {
                $sides[$key]['label'] = $side['label'];
                $sides[$key]['totals'][$id] = $side['closing'];

                foreach ($side['groups'] as $index => $group) {
                    $sides[$key]['groups'][$index] ??= ['key' => (string) $index, 'label' => $group['label'], 'rows' => [], 'totals' => [], 'subtotal' => null];
                    $sides[$key]['groups'][$index]['totals'][$id] = $group['closing'];

                    foreach ($group['rows'] as $row) {
                        $sides[$key]['groups'][$index]['rows'][$row['number']] ??= ['number' => $row['number'], 'name' => $row['name'], 'name_differs' => false, 'amounts' => []];
                        $sides[$key]['groups'][$index]['rows'][$row['number']]['name_differs'] = $sides[$key]['groups'][$index]['rows'][$row['number']]['name_differs'] || $sides[$key]['groups'][$index]['rows'][$row['number']]['name'] !== $row['name'];
                        $sides[$key]['groups'][$index]['rows'][$row['number']]['amounts'][$id] = $row['closing'];
                    }
                }
            }

            $sides['equity']['unbooked']['amounts'][$id] = (int) $reports[$id]['balance']['unbooked_result']['closing'];
        }

        foreach ($sides as $key => $side) {
            $sides[$key]['groups'] = array_values(array_map(fn (array $group): array => $this->finish($group, $ids), $side['groups']));
            $sides[$key]['sum'] = array_sum($side['totals']);
            $sides[$key]['unbooked']['sum'] = array_sum($side['unbooked']['amounts']);
        }

        return ['sides' => $sides];
    }

    /** @return array<string, array<string, mixed>> */
    private function emptySides(): array
    {
        $side = ['label' => '', 'groups' => [], 'totals' => [], 'unbooked' => ['label' => __('Resultat som ännu inte är bokfört mot eget kapital'), 'amounts' => []]];

        return ['assets' => $side, 'equity' => $side];
    }

    /**
     * Fills in zeros for companies that have no amount on a row, sorts the
     * rows by account number and adds the sum column.
     *
     * @param  array<string, mixed>  $group
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function finish(array $group, array $ids): array
    {
        ksort($group['rows']);
        $group['rows'] = array_values(array_map(function (array $row) use ($ids): array {
            $row['amounts'] = $this->complete($row['amounts'], $ids);
            $row['sum'] = array_sum($row['amounts']);

            return $row;
        }, $group['rows']));

        $group['totals'] = $this->complete($group['totals'], $ids);
        $group['sum'] = array_sum($group['totals']);

        if ($group['subtotal'] !== null) {
            $group['subtotal']['amounts'] = $this->complete($group['subtotal']['amounts'], $ids);
            $group['subtotal']['sum'] = array_sum($group['subtotal']['amounts']);
        }

        return $group;
    }

    /**
     * @param  array<int, int>  $amounts
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function complete(array $amounts, array $ids): array
    {
        $complete = [];

        foreach ($ids as $id) {
            $complete[$id] = (int) ($amounts[$id] ?? 0);
        }

        return $complete;
    }

    /**
     * Per company and account number: assets and the income statement the
     * way the reports show them (assets and revenue positive), and the
     * equity and liabilities side positive for credit.
     *
     * @param  list<int>  $ids
     * @param  array<int, array{income: array<string, mixed>, balance: array<string, mixed>}>  $reports
     * @return array<int, array{assets: array<int, int>, credit: array<int, int>, income: array<int, int>, names: array<int, string>}>
     */
    private function amountsByNumber(array $ids, array $reports): array
    {
        $amounts = [];

        foreach ($ids as $id) {
            $amounts[$id] = ['assets' => [], 'credit' => [], 'income' => [], 'names' => []];

            foreach ($reports[$id]['balance']['sides'] as $key => $side) {
                foreach ($side['groups'] as $group) {
                    foreach ($group['rows'] as $row) {
                        $amounts[$id][$key === 'assets' ? 'assets' : 'credit'][$row['number']] = $row['closing'];
                        $amounts[$id]['names'][$row['number']] = $row['name'];
                    }
                }
            }

            foreach ($reports[$id]['income']['groups'] as $group) {
                foreach ($group['rows'] as $row) {
                    $amounts[$id]['income'][$row['number']] = $row['amount'];
                    $amounts[$id]['names'][$row['number']] = $row['name'];
                }
            }
        }

        return $amounts;
    }

    /**
     * The marked accounts of the companies shown, with their amounts as
     * positive magnitudes on their own side (a receivable as a debit, a
     * liability, sale and equity as a credit, a purchase as a cost).
     *
     * @param  list<array{id: int, name: string, org_number: ?string, ownership_bp: ?int}>  $companies
     * @param  array<int, array{assets: array<int, int>, credit: array<int, int>, income: array<int, int>, names: array<int, string>}>  $amounts
     * @return list<array{company_id: int, company: string, number: int, name: string, kind: MarkKind, amount: int}>
     */
    private function marks(CompanyGroup $group, array $companies, array $amounts): array
    {
        $names = array_column($companies, 'name', 'id');
        $rows = [];

        $marks = CompanyGroupMark::query()
            ->where('company_group_id', $group->id)
            ->whereIn('company_id', array_keys($names))
            ->orderBy('company_id')->orderBy('account_number')
            ->get();

        foreach ($marks as $mark) {
            $kind = MarkKind::tryFrom($mark->kind);

            if ($kind === null) {
                continue;
            }

            $of = $amounts[$mark->company_id];
            $number = $mark->account_number;

            $amount = match ($kind) {
                MarkKind::Shares, MarkKind::Receivable => $of['assets'][$number] ?? 0,
                MarkKind::Payable, MarkKind::Equity => $of['credit'][$number] ?? 0,
                MarkKind::Sales => $of['income'][$number] ?? 0,
                MarkKind::Purchases => -($of['income'][$number] ?? 0),
            };

            $rows[] = [
                'company_id' => $mark->company_id,
                'company' => $names[$mark->company_id],
                'number' => $number,
                'name' => $of['names'][$number] ?? '',
                'kind' => $kind,
                'amount' => $amount,
            ];
        }

        return $rows;
    }

    /**
     * Suggestions only: the smaller side of each pair is what could be
     * eliminated, the rest is a difference to look into. Nothing is booked.
     *
     * @param  list<array{company_id: int, company: string, number: int, name: string, kind: MarkKind, amount: int}>  $marks
     * @param  array{groups: list<array<string, mixed>>, result: array{amounts: array<int, int>, sum: int}}  $income
     * @param  array{sides: array<string, array<string, mixed>>}  $balance
     * @return array{eliminations: list<array{key: string, label: string, left_label: string, right_label: string, left: int, right: int, eliminate: int, difference: int}>, adjusted: list<array{label: string, before: int, after: int}>}
     */
    private function eliminate(array $marks, array $income, array $balance): array
    {
        $sum = fn (MarkKind $kind): int => array_sum(array_map(fn (array $mark): int => $mark['kind'] === $kind ? $mark['amount'] : 0, $marks));

        $pair = function (string $key, string $label, MarkKind $left, MarkKind $right) use ($sum): array {
            $l = $sum($left);
            $r = $sum($right);

            return [
                'key' => $key,
                'label' => $label,
                'left_label' => $left->label(),
                'right_label' => $right->label(),
                'left' => $l,
                'right' => $r,
                'eliminate' => max(0, min($l, $r)),
                'difference' => $l - $r,
            ];
        };

        $pairs = [
            $pair('balances', __('Koncerninterna fordringar och skulder'), MarkKind::Receivable, MarkKind::Payable),
            $pair('trade', __('Koncernintern försäljning och inköp'), MarkKind::Sales, MarkKind::Purchases),
            $pair('shares', __('Andelar och eget kapital i dotterföretag'), MarkKind::Shares, MarkKind::Equity),
        ];

        $eliminate = array_column($pairs, 'eliminate', 'key');
        $assets = (int) ($balance['sides']['assets']['sum'] ?? 0);
        $liabilities = (int) ($balance['sides']['equity']['sum'] ?? 0);
        $revenue = (int) ($income['groups'][0]['sum'] ?? 0);

        return [
            'eliminations' => $pairs,
            'adjusted' => [
                ['label' => __('Tillgångar'), 'before' => $assets, 'after' => $assets - $eliminate['balances'] - $eliminate['shares']],
                ['label' => __('Eget kapital och skulder'), 'before' => $liabilities, 'after' => $liabilities - $eliminate['balances'] - $eliminate['shares']],
                ['label' => __('Rörelsens intäkter'), 'before' => $revenue, 'after' => $revenue - $eliminate['trade']],
            ],
        ];
    }
}
