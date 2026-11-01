<?php

declare(strict_types=1);

namespace App\Domain\Firm;

use App\Domain\Accounting\VoucherStatus;
use App\Domain\Company\CompanyOverview;
use App\Domain\Company\LegalForm;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Domain\Tasks\TaskPriority;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use App\Models\AnnualReport;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\OpeningBalance;
use App\Models\SupplierInvoice;
use App\Models\Task;
use App\Models\User;
use App\Models\VoucherRow;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Models\InboxMessage;
use App\Policies\SupplierInvoicePolicy;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mina företag, aggregated (ADR-028): totals across the user's companies and
 * one prioritised list of what to do, the system's to-dos and open tasks
 * together, each naming its company.
 *
 * Every figure is one grouped query across all the user's companies, so the
 * number of queries does not grow with the number of companies. A company's
 * figure is only counted when the user's role there may read it.
 *
 * @phpstan-type Item array{
 *     key: string, source: string, company_id: int, company_name: string, type: string, type_label: string,
 *     label: string, count: int, due: ?string, overdue: bool, urgent: bool, status: string, status_label: string,
 *     priority: string, assignee_id: ?int, assignee_name: ?string, responsible_id: ?int,
 *     destination: ?string, task_id: ?int
 * }
 * @phpstan-type CompanyRow array{
 *     id: int, name: string, org_number: string, responsible_id: ?int, responsible_name: ?string,
 *     bank: ?int, receivables: ?int, payables: ?int, vat: ?int, open_items: int
 * }
 */
final class FirmWorkspace
{
    /** System to-dos on Mina företag, by the destination they open, as task types. */
    private const array SYSTEM_TYPES = [
        'vat' => TaskType::Vat,
        'agi' => TaskType::Agi,
        'fiscal-years' => TaskType::YearEnd,
        'invoices' => TaskType::Invoices,
        'supplier-invoices' => TaskType::Approval,
        'vouchers' => TaskType::Documents,
        'payroll-runs' => TaskType::Payroll,
        'payroll-requests' => TaskType::Payroll,
    ];

    private const array CASH = [1900, 1999];

    private const array VAT = [2600, 2699];

    /** How long after the end of the fiscal year an aktiebolag's annual report must reach Bolagsverket. */
    // TODO(legal-verify): seven months for aktiebolag (ÅRL 8 kap. 3 §); shown as a reminder only.
    private const int ANNUAL_REPORT_MONTHS = 7;

    public function __construct(private readonly CompanyOverview $overview) {}

    /**
     * Cached per user (FirmCache); the result is plain arrays.
     *
     * @return array{
     *     totals: array{bank: int, receivables: int, payables: int, vat: int, companies: int, overdue: int, due_week: int, waiting: int},
     *     companies: list<CompanyRow>,
     *     items: list<Item>,
     *     consultants: array<int, string>,
     *     generated_at: string
     * }
     */
    public function for(User $user, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $memberships = Memberships::of($user, active: true);

        return FirmCache::remember($user, 'workspace', $memberships->ids(), ['day' => $today->toDateString(), 'locale' => app()->getLocale()], fn (): array => $this->compute($memberships, $today));
    }

    /**
     * @return array{
     *     totals: array{bank: int, receivables: int, payables: int, vat: int, companies: int, overdue: int, due_week: int, waiting: int},
     *     companies: list<CompanyRow>,
     *     items: list<Item>,
     *     consultants: array<int, string>,
     *     generated_at: string
     * }
     */
    public function compute(Memberships $memberships, CarbonImmutable $today): array
    {
        $user = $memberships->user;
        $ids = $memberships->ids();
        $overviewRows = $ids === [] ? [] : $this->overview->for($user, $today);
        $responsible = [];
        $names = [];
        $items = [];

        foreach ($overviewRows as $row) {
            $id = $row['company']->id;

            if (! $memberships->has($id)) {
                continue;
            }

            $names[$id] = (string) $row['company']->name;
            $responsible[$id] = $row['responsible'];

            foreach ($row['tasks'] as $task) {
                $type = self::SYSTEM_TYPES[$task['destination']] ?? TaskType::Other;
                $items[] = $this->systemItem($id, $names[$id], $type, $task['label'], $task['count'], $task['due'], $task['overdue'], $task['urgent'], $task['destination'], $responsible[$id]['id'] ?? null);
            }
        }

        if ($ids === []) {
            return ['totals' => $this->totals([], [], $today), 'companies' => [], 'items' => [], 'consultants' => [], 'generated_at' => now()->toIso8601String()];
        }

        // Read across the user's own companies at once; every id comes from the user's memberships.
        $extra = CompanyScope::bypass(fn (): array => [
            'figures' => $this->figures($memberships, $today),
            'inbox' => $this->inbox($memberships),
            'bank' => $this->unmatchedBank($memberships),
            'annual' => $this->annualReports($memberships, $today),
            'tasks' => $this->tasks($memberships),
        ]);

        foreach ($extra['inbox'] as $id => $count) {
            $items[] = $this->systemItem($id, $names[$id], TaskType::Inbox, __('Inkorgen: post att granska'), $count, null, false, false, 'inbox', $responsible[$id]['id'] ?? null);
        }

        foreach ($extra['bank'] as $id => $count) {
            $items[] = $this->systemItem($id, $names[$id], TaskType::Bank, __('Bankposter att stämma av'), $count, null, false, false, 'incoming-payments', $responsible[$id]['id'] ?? null);
        }

        foreach ($extra['annual'] as $id => $due) {
            $items[] = $this->systemItem($id, $names[$id], TaskType::AnnualReport, __('Årsredovisning att lämna'), 1, $due->toDateString(), $due->lessThan($today), $due->lessThanOrEqualTo($today->addDays(30)), 'annual-report', $responsible[$id]['id'] ?? null);
        }

        foreach ($extra['tasks'] as $task) {
            $due = $task->due_date;
            $items[] = [
                'key' => "task:{$task->id}",
                'source' => 'task',
                'company_id' => $task->company_id,
                'company_name' => $names[$task->company_id] ?? '',
                'type' => $task->type->value,
                'type_label' => $task->type->label(),
                'label' => $task->title,
                'count' => 1,
                'due' => $due?->toDateString(),
                'overdue' => $due !== null && $due->lessThan($today),
                'urgent' => $task->priority === TaskPriority::High || ($due !== null && $due->lessThanOrEqualTo($today->addDays(7))),
                'status' => $task->status->value,
                'status_label' => $task->status->label(),
                'priority' => $task->priority->value,
                'assignee_id' => $task->assignee_id,
                'assignee_name' => $task->assignee?->name,
                'responsible_id' => $responsible[$task->company_id]['id'] ?? null,
                'destination' => null,
                'task_id' => $task->id,
            ];
        }

        $items = $this->sort($items);
        $open = [];

        foreach ($items as $item) {
            $open[$item['company_id']] = ($open[$item['company_id']] ?? 0) + 1;
        }

        $companies = [];
        $consultants = [];

        foreach ($memberships->companies as $company) {
            $id = $company->id;
            $figures = $extra['figures'][$id] ?? [];

            if (isset($responsible[$id])) {
                $consultants[$responsible[$id]['id']] = $responsible[$id]['name'];
            }

            $companies[] = [
                'id' => $id,
                'name' => (string) $company->name,
                'org_number' => (string) $company->org_number,
                'responsible_id' => $responsible[$id]['id'] ?? null,
                'responsible_name' => $responsible[$id]['name'] ?? null,
                'bank' => $figures['bank'] ?? null,
                'receivables' => $figures['receivables'] ?? null,
                'payables' => $figures['payables'] ?? null,
                'vat' => $figures['vat'] ?? null,
                'open_items' => $open[$id] ?? 0,
            ];
        }

        asort($consultants, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'totals' => $this->totals($companies, $items, $today),
            'companies' => $companies,
            'items' => $items,
            'consultants' => $consultants,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Overdue first, then by due date (none last), high priority, then the
     * company's name.
     *
     * @param  list<Item>  $items
     * @return list<Item>
     */
    private function sort(array $items): array
    {
        usort($items, fn (array $a, array $b): int => [
            $a['overdue'] ? 0 : 1, $a['due'] ?? '9999-12-31', TaskPriority::from($a['priority'])->rank(), mb_strtolower($a['company_name']), $a['label'],
        ] <=> [
            $b['overdue'] ? 0 : 1, $b['due'] ?? '9999-12-31', TaskPriority::from($b['priority'])->rank(), mb_strtolower($b['company_name']), $b['label'],
        ]);

        return $items;
    }

    /**
     * @param  list<CompanyRow>  $companies
     * @param  list<Item>  $items
     * @return array{bank: int, receivables: int, payables: int, vat: int, companies: int, overdue: int, due_week: int, waiting: int}
     */
    private function totals(array $companies, array $items, CarbonImmutable $today): array
    {
        $week = $today->endOfWeek()->toDateString();

        return [
            'bank' => array_sum(array_map(fn (array $row): int => (int) $row['bank'], $companies)),
            'receivables' => array_sum(array_map(fn (array $row): int => (int) $row['receivables'], $companies)),
            'payables' => array_sum(array_map(fn (array $row): int => (int) $row['payables'], $companies)),
            'vat' => array_sum(array_map(fn (array $row): int => (int) $row['vat'], $companies)),
            'companies' => count($companies),
            'overdue' => count(array_filter($items, fn (array $item): bool => $item['overdue'])),
            'due_week' => count(array_filter($items, fn (array $item): bool => ! $item['overdue'] && $item['due'] !== null && $item['due'] <= $week)),
            'waiting' => count(array_filter($items, fn (array $item): bool => $item['status'] === TaskStatus::WaitingForClient->value)),
        ];
    }

    /** @return Item */
    private function systemItem(int $companyId, string $companyName, TaskType $type, string $label, int $count, ?string $due, bool $overdue, bool $urgent, string $destination, ?int $responsibleId): array
    {
        return [
            'key' => "system:{$companyId}:{$destination}:{$label}",
            'source' => 'system',
            'company_id' => $companyId,
            'company_name' => $companyName,
            'type' => $type->value,
            'type_label' => $type->label(),
            'label' => $label,
            'count' => $count,
            'due' => $due,
            'overdue' => $overdue,
            'urgent' => $urgent,
            'status' => TaskStatus::Todo->value,
            'status_label' => TaskStatus::Todo->label(),
            'priority' => $urgent ? TaskPriority::High->value : TaskPriority::Normal->value,
            'assignee_id' => null,
            'assignee_name' => null,
            'responsible_id' => $responsibleId,
            'destination' => $destination,
            'task_id' => null,
        ];
    }

    /**
     * Money in bank and cash and the VAT balance from the ledger of the
     * fiscal year that contains today (with its opening balances), what
     * customers owe and what is owed to suppliers. Öre, per company.
     *
     * @return array<int, array{bank?: int, vat?: int, receivables?: int, payables?: int}>
     */
    private function figures(Memberships $memberships, CarbonImmutable $today): array
    {
        $figures = [];
        $ledgerIds = $memberships->where('reports:view');
        $invoiceIds = $memberships->where('invoices:view');
        $payableIds = array_values(array_filter($memberships->ids(), function (int $id) use ($memberships): bool {
            foreach (SupplierInvoicePolicy::VIEW_PERMISSIONS as $permission) {
                if ($memberships->may($id, $permission)) {
                    return true;
                }
            }

            return false;
        }));
        $date = $today->toDateString();

        if ($ledgerIds !== []) {
            $movements = VoucherRow::query()->toBase()
                ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
                ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
                ->join('fiscal_years', 'fiscal_years.id', '=', 'vouchers.fiscal_year_id')
                ->whereIn('vouchers.company_id', $ledgerIds)
                ->where('vouchers.status', VoucherStatus::Posted->value)
                ->whereDate('fiscal_years.start_date', '<=', $date)
                ->whereDate('fiscal_years.end_date', '>=', $date)
                ->groupBy('vouchers.company_id')
                ->select('vouchers.company_id')
                ->selectRaw('COALESCE(SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) FILTER (WHERE accounts.number BETWEEN ? AND ?), 0) AS cash', self::CASH)
                ->selectRaw('COALESCE(SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) FILTER (WHERE accounts.number BETWEEN ? AND ?), 0) AS vat', self::VAT)
                ->get();
            $opening = OpeningBalance::query()->toBase()
                ->join('accounts', 'accounts.id', '=', 'opening_balances.account_id')
                ->join('fiscal_years', 'fiscal_years.id', '=', 'opening_balances.fiscal_year_id')
                ->whereIn('opening_balances.company_id', $ledgerIds)
                ->whereDate('fiscal_years.start_date', '<=', $date)
                ->whereDate('fiscal_years.end_date', '>=', $date)
                ->groupBy('opening_balances.company_id')
                ->select('opening_balances.company_id')
                ->selectRaw('COALESCE(SUM(opening_balances.amount_ore) FILTER (WHERE accounts.number BETWEEN ? AND ?), 0) AS cash', self::CASH)
                ->selectRaw('COALESCE(SUM(opening_balances.amount_ore) FILTER (WHERE accounts.number BETWEEN ? AND ?), 0) AS vat', self::VAT)
                ->get()->keyBy('company_id');

            $bank = [];
            $vat = [];

            foreach ([...$movements->all(), ...$opening->all()] as $row) {
                $id = (int) $row->company_id;
                $bank[$id] = ($bank[$id] ?? 0) + (int) $row->cash;
                // A credit balance on the VAT accounts is VAT to pay, as on the overview.
                $vat[$id] = ($vat[$id] ?? 0) - (int) $row->vat;
            }

            foreach ($ledgerIds as $id) {
                $figures[$id] = ['bank' => $bank[$id] ?? 0, 'vat' => $vat[$id] ?? 0];
            }
        }

        if ($invoiceIds !== []) {
            $receivables = Invoice::query()->toBase()->whereIn('company_id', $invoiceIds)
                ->where('status', InvoiceStatus::Issued->value)->where('kind', Invoice::INVOICE)
                ->groupBy('company_id')->select('company_id')->selectRaw('SUM(total_ore - paid_ore) AS amount')
                ->pluck('amount', 'company_id');

            foreach ($invoiceIds as $id) {
                $figures[$id]['receivables'] = (int) ($receivables[$id] ?? 0);
            }
        }

        if ($payableIds !== []) {
            $payables = SupplierInvoice::query()->toBase()->whereIn('company_id', $payableIds)
                ->whereIn('status', [SupplierInvoiceStatus::Registered->value, SupplierInvoiceStatus::Approved->value])
                ->groupBy('company_id')->select('company_id')->selectRaw('SUM(total_ore - paid_ore) AS amount')
                ->pluck('amount', 'company_id');

            foreach ($payableIds as $id) {
                $figures[$id]['payables'] = (int) ($payables[$id] ?? 0);
            }
        }

        return $figures;
    }

    /** @return array<int, int> open inbox messages per company with the inbox on */
    private function inbox(Memberships $memberships): array
    {
        $ids = $memberships->where('inbox:view');

        if ($ids === [] || ! class_exists(InboxModule::class)) {
            return [];
        }

        return InboxMessage::query()->whereIn('company_id', $ids)
            ->whereIn('status', [MessageStatus::Quarantine->value, MessageStatus::NeedsReview->value, MessageStatus::DraftReady->value])
            ->groupBy('company_id')->select('company_id', DB::raw('count(*) as aggregate'))
            ->pluck('aggregate', 'company_id')->map(fn (mixed $count): int => (int) $count)->all();
    }

    /** @return array<int, int> bank payments read in but not matched, per company */
    private function unmatchedBank(Memberships $memberships): array
    {
        $ids = $memberships->where('invoices:create');

        if ($ids === []) {
            return [];
        }

        return BankTransaction::query()->whereIn('company_id', $ids)->whereNull('invoice_id')
            ->groupBy('company_id')->select('company_id', DB::raw('count(*) as aggregate'))
            ->pluck('aggregate', 'company_id')->map(fn (mixed $count): int => (int) $count)->all();
    }

    /**
     * Aktiebolag with a fiscal year that ended within the last year and no
     * signed annual report for it: the last day for the report.
     *
     * @return array<int, CarbonImmutable>
     */
    private function annualReports(Memberships $memberships, CarbonImmutable $today): array
    {
        $ids = array_values(array_filter(
            $memberships->where('fiscal-years:manage'),
            fn (int $id): bool => $memberships->companies->firstWhere('id', $id)?->legal_form === LegalForm::Ab,
        ));

        if ($ids === []) {
            return [];
        }

        $years = FiscalYear::query()->whereIn('company_id', $ids)
            ->whereDate('end_date', '<', $today->toDateString())
            ->whereDate('end_date', '>=', $today->subYear()->toDateString())
            ->orderBy('end_date')->get(['id', 'company_id', 'end_date']);
        $signed = AnnualReport::query()->whereIn('fiscal_year_id', $years->modelKeys())->whereNotNull('signing_date')
            ->pluck('fiscal_year_id')->map(fn (mixed $id): int => (int) $id)->all();
        $due = [];

        foreach ($years as $year) {
            if (! in_array($year->id, $signed, true) && ! isset($due[$year->company_id])) {
                $due[$year->company_id] = $year->end_date->addMonthsNoOverflow(self::ANNUAL_REPORT_MONTHS)->endOfMonth()->startOfDay();
            }
        }

        return $due;
    }

    /**
     * Open tasks the user may see: every task in companies where the user
     * holds tasks:view, and the user's own in the others.
     *
     * @return Collection<int, Task>
     */
    private function tasks(Memberships $memberships): Collection
    {
        $viewIds = $memberships->where('tasks:view');

        return Task::query()->open()->whereIn('company_id', $memberships->ids())
            ->where(fn ($query) => $query->whereIn('company_id', $viewIds)->orWhere('assignee_id', $memberships->user->id))
            ->with('assignee:id,name')
            ->orderByRaw('due_date asc nulls last')->orderBy('id')
            ->limit(1000)
            ->get();
    }

    /**
     * The aggregated list, filtered. Filters: ansvarig (user id or "ingen"),
     * frist (vecka, manad, forsenad), typ (TaskType slug), status (TaskStatus
     * slug), mina (only items assigned to the user).
     *
     * @param  list<Item>  $items
     * @param  array<string, string>  $filters
     * @return list<Item>
     */
    public static function filter(array $items, array $filters, User $user, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $responsible = $filters['ansvarig'] ?? '';
        $deadline = $filters['frist'] ?? '';
        $type = TaskType::fromSlug($filters['typ'] ?? '');
        $status = TaskStatus::fromSlug($filters['status'] ?? '');
        $mine = ($filters['mina'] ?? '') === '1';
        $limit = match ($deadline) {
            'vecka' => $today->endOfWeek()->toDateString(),
            'manad' => $today->endOfMonth()->toDateString(),
            default => null,
        };

        return array_values(array_filter($items, fn (array $item): bool => match (true) {
            $responsible === 'ingen' && $item['responsible_id'] !== null => false,
            $responsible !== '' && $responsible !== 'ingen' && (string) $item['responsible_id'] !== $responsible => false,
            $deadline === 'forsenad' && ! $item['overdue'] => false,
            $limit !== null && ($item['due'] === null || $item['due'] > $limit) => false,
            $type !== null && $item['type'] !== $type->value => false,
            $status !== null && $item['status'] !== $status->value => false,
            $mine && $item['assignee_id'] !== $user->id => false,
            default => true,
        }));
    }
}
