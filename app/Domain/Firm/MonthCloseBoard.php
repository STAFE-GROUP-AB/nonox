<?php

declare(strict_types=1);

namespace App\Domain\Firm;

use App\Domain\Company\CompanyOverview;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Domain\Tasks\MonthCloseStep;
use App\Models\Accrual;
use App\Models\AccrualPosting;
use App\Models\BankReconciliation;
use App\Models\Invoice;
use App\Models\MonthCloseCheck;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Månadsstängning (ADR-028): one row per client, one cell per step of the
 * month. A cell is derived from the data where it can be and otherwise
 * checked off by hand; a check-off by hand wins over a derived "to do".
 * One grouped query per source, whatever the number of companies.
 *
 * Cell states: done, todo, late, open (the month or period has not ended),
 * na (nothing to do for the company).
 *
 * @phpstan-type Cell array{state: string, manual: bool, count: ?int, checked_by: ?string, checked_at: ?string, due: ?string}
 */
final class MonthCloseBoard
{
    public const int MONTHS_BACK = 12;

    public function __construct(private readonly CompanyOverview $overview) {}

    /**
     * The months to choose from, newest first: this month and twelve back.
     *
     * @return list<string>
     */
    public static function months(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        return array_map(fn (int $i): string => $today->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m'), range(0, self::MONTHS_BACK));
    }

    /**
     * @return list<array{company_id: int, company_name: string, responsible_id: ?int, responsible_name: ?string, can_check: bool, done: int, total: int, cells: array<string, Cell>}>
     */
    public function for(User $user, string $month, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $memberships = Memberships::of($user);
        $ids = $memberships->where('tasks:view');

        if ($ids === [] || ! in_array($month, self::months($today), true)) {
            return [];
        }

        return FirmCache::remember($user, 'month-close', $memberships->ids(), ['month' => $month, 'day' => $today->toDateString()], fn (): array => $this->compute($user, $memberships, $ids, $month, $today));
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{company_id: int, company_name: string, responsible_id: ?int, responsible_name: ?string, can_check: bool, done: int, total: int, cells: array<string, Cell>}>
     */
    private function compute(User $user, Memberships $memberships, array $ids, string $month, CarbonImmutable $today): array
    {
        $start = CarbonImmutable::parse("{$month}-01");
        $end = $start->endOfMonth()->startOfDay();
        $ended = $end->lessThan($today);
        $back = ($today->startOfMonth()->year - $start->year) * 12 + $today->startOfMonth()->month - $start->month;
        $status = $this->overview->months($user, back: $back, ahead: 0, today: $today);
        $vatAgi = [];

        foreach ($status['rows'] as $row) {
            $vatAgi[$row['company']->id] = $row['cells'][$month] ?? null;
        }

        // Read across the user's own companies at once; every id comes from the user's memberships.
        $data = CompanyScope::bypass(fn (): array => [
            'reconciled' => BankReconciliation::query()->whereIn('company_id', $ids)->whereDate('statement_date', '>=', $end->toDateString())
                ->distinct()->pluck('company_id')->map(fn (mixed $id): int => (int) $id)->all(),
            'supplier' => SupplierInvoice::query()->whereIn('company_id', $ids)->where('status', SupplierInvoiceStatus::Registered->value)
                ->whereDate('invoice_date', '<=', $end->toDateString())
                ->groupBy('company_id')->select('company_id', DB::raw('count(*) as aggregate'))->pluck('aggregate', 'company_id')->all(),
            'drafts' => Invoice::query()->whereIn('company_id', $ids)->where('status', InvoiceStatus::Draft->value)
                ->whereDate('invoice_date', '<=', $end->toDateString())
                ->groupBy('company_id')->select('company_id', DB::raw('count(*) as aggregate'))->pluck('aggregate', 'company_id')->all(),
            'accruals' => Accrual::query()->whereIn('company_id', $ids)->where('start_month', '<=', $month)
                ->whereRaw("to_char(to_date(start_month || '-01', 'YYYY-MM-DD') + make_interval(months => months), 'YYYY-MM') > ?", [$month])
                ->groupBy('company_id')->select('company_id', DB::raw('count(*) as aggregate'))->pluck('aggregate', 'company_id')->all(),
            'posted' => AccrualPosting::query()->whereIn('company_id', $ids)->where('period', $month)
                ->groupBy('company_id')->select('company_id', DB::raw('count(distinct accrual_id) as aggregate'))->pluck('aggregate', 'company_id')->all(),
            'checks' => MonthCloseCheck::query()->whereIn('company_id', $ids)->where('month', $month)
                ->leftJoin('users', 'users.id', '=', 'month_close_checks.checked_by')
                ->get(['month_close_checks.company_id', 'month_close_checks.step', 'month_close_checks.checked_at', 'users.name'])
                ->groupBy('company_id')->all(),
            'profiles' => DB::table('client_profiles')->join('users', 'users.id', '=', 'client_profiles.responsible_user_id')
                ->whereIn('client_profiles.company_id', $ids)->get(['client_profiles.company_id', 'users.id', 'users.name'])->keyBy('company_id')->all(),
        ]);

        $rows = [];

        foreach ($memberships->companies as $company) {
            $id = $company->id;

            if (! in_array($id, $ids, true)) {
                continue;
            }

            $status = $vatAgi[$id] ?? null;
            $accrualsDue = (int) ($data['accruals'][$id] ?? 0);
            $accrualsPosted = (int) ($data['posted'][$id] ?? 0);
            $supplier = (int) ($data['supplier'][$id] ?? 0);
            $drafts = (int) ($data['drafts'][$id] ?? 0);
            $derived = [
                MonthCloseStep::Bank->value => [in_array($id, $data['reconciled'], true) ? 'done' : ($ended ? 'todo' : 'open'), null, null],
                MonthCloseStep::SupplierInvoices->value => [$supplier === 0 ? ($ended ? 'done' : 'open') : 'todo', $supplier ?: null, null],
                MonthCloseStep::CustomerInvoices->value => [$drafts === 0 ? ($ended ? 'done' : 'open') : 'todo', $drafts ?: null, null],
                MonthCloseStep::Vat->value => [$this->fromOverview($status['vat'] ?? null), null, $status['vat_due'] ?? null],
                MonthCloseStep::Payroll->value => [$this->fromOverview($status['agi'] ?? null), null, $status['agi_due'] ?? null],
                MonthCloseStep::Accruals->value => [$accrualsDue === 0 ? 'na' : ($accrualsPosted >= $accrualsDue ? 'done' : ($ended ? 'todo' : 'open')), $accrualsDue > $accrualsPosted ? $accrualsDue - $accrualsPosted : null, null],
                MonthCloseStep::Report->value => [$ended ? 'todo' : 'open', null, null],
            ];
            $checks = collect($data['checks'][$id] ?? [])->keyBy(fn (MonthCloseCheck $check): string => $check->step->value);
            $cells = [];

            foreach (MonthCloseStep::cases() as $step) {
                [$state, $count, $due] = $derived[$step->value];
                $check = $checks->get($step->value);
                $manual = $check instanceof MonthCloseCheck;

                $cells[$step->value] = [
                    'state' => $manual ? 'done' : $state,
                    'manual' => $manual,
                    'count' => $manual ? null : $count,
                    'checked_by' => $manual ? (string) $check->getAttribute('name') : null,
                    'checked_at' => $manual ? $check->checked_at->toDateString() : null,
                    'due' => $due,
                ];
            }

            $relevant = array_filter($cells, fn (array $cell): bool => $cell['state'] !== 'na');

            $rows[] = [
                'company_id' => $id,
                'company_name' => (string) $company->name,
                'responsible_id' => isset($data['profiles'][$id]) ? (int) $data['profiles'][$id]->id : null,
                'responsible_name' => isset($data['profiles'][$id]) ? (string) $data['profiles'][$id]->name : null,
                'can_check' => $memberships->may($id, 'tasks:manage'),
                'done' => count(array_filter($relevant, fn (array $cell): bool => $cell['state'] === 'done')),
                'total' => count($relevant),
                'cells' => $cells,
            ];
        }

        // Least done first, so what needs attention is on top.
        usort($rows, fn (array $a, array $b): int => [$a['total'] === 0 ? 1 : $a['done'] / $a['total'], mb_strtolower($a['company_name'])] <=> [$b['total'] === 0 ? 1 : $b['done'] / $b['total'], mb_strtolower($b['company_name'])]);

        return $rows;
    }

    private function fromOverview(?string $state): string
    {
        return match ($state) {
            'REPORTED' => 'done',
            'DUE' => 'todo',
            'LATE' => 'late',
            'OPEN' => 'open',
            default => 'na',
        };
    }
}
