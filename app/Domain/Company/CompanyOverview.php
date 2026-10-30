<?php

declare(strict_types=1);

namespace App\Domain\Company;

use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Domain\Vat\VatPeriods;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\VatReport;
use App\Models\Voucher;
use App\Modules\ModuleRegistry;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\Expense;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\PayrollModule;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Jetstream;

/**
 * Every company a user works in, with what waits in each: drafts, supplier
 * invoices to approve, overdue customer invoices, VAT periods and employer
 * declarations not yet reported, years to close and payroll to handle. Made
 * for accounting firms with many clients and owners with several companies.
 *
 * Each figure is one query across all the user's companies, whatever their
 * number, and a figure is only shown where the user's role may act on it.
 */
final class CompanyOverview
{
    /** Pages a row may open in its company; the switch accepts only these. */
    public const array DESTINATIONS = [
        'dashboard' => 'dashboard',
        'vouchers' => 'accounting.vouchers.index',
        'supplier-invoices' => 'payables.invoices.index',
        'invoices' => 'invoicing.invoices.index',
        'vat' => 'vat.index',
        'fiscal-years' => 'accounting.fiscalYears',
        'agi' => 'payroll.agi',
        'payroll-runs' => 'payroll.runs.index',
        'payroll-requests' => 'payroll.requests',
    ];

    /** @var array<int, array<int, string>> enabled module keys per company */
    private array $modules = [];

    /** @var array<int, ?string> the user's role per company, null for the owner */
    private array $roles = [];

    /**
     * @return list<array{
     *     company: Company,
     *     role: string,
     *     tasks: list<array{key: string, label: string, count: int, destination: string, urgent: bool}>,
     *     last_posted: ?string,
     *     attention: int
     * }>
     */
    public function for(User $user, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $companies = $user->companies();
        $ids = $companies->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        foreach ($companies as $company) {
            $this->roles[$company->id] = $user->ownsTeam($company) ? null : (string) $company->getRelationValue('membership')?->getAttribute('role');
        }

        // Read across the user's own companies at once; each id comes from their memberships.
        return CompanyScope::bypass(function () use ($companies, $ids, $today): array {
            $this->modules = CompanyModule::query()->whereIn('company_id', $ids)->where('enabled', true)->get(['company_id', 'module_key'])
                ->groupBy('company_id')->map(fn (Collection $rows): array => $rows->pluck('module_key')->all())->all();

            $drafts = $this->countBy(Voucher::query()->whereIn('company_id', $ids)->where('status', VoucherStatus::Draft->value));
            $toApprove = $this->countBy(SupplierInvoice::query()->whereIn('company_id', $ids)->where('status', SupplierInvoiceStatus::Registered->value));
            $toPay = $this->countBy(SupplierInvoice::query()->whereIn('company_id', $ids)->where('status', SupplierInvoiceStatus::Approved->value)->whereDate('due_date', '<=', $today->addDays(7)->toDateString()));
            $overdue = $this->countBy(Invoice::query()->whereIn('company_id', $ids)->where('status', InvoiceStatus::Issued->value)->whereDate('due_date', '<', $today->toDateString()));
            $lastPosted = Voucher::query()->whereIn('company_id', $ids)->where('status', VoucherStatus::Posted->value)
                ->groupBy('company_id')->select('company_id', DB::raw('max(voucher_date) as last'))->pluck('last', 'company_id')->all();
            $years = FiscalYear::query()->whereIn('company_id', $ids)->orderBy('start_date')->get()->groupBy('company_id');
            $reported = VatReport::query()->whereIn('company_id', $ids)->get(['company_id', 'period_start'])
                ->groupBy('company_id')->map(fn (Collection $rows): array => $rows->map(fn (VatReport $report): string => $report->period_start->toDateString())->all())->all();
            $payroll = $this->payroll(array_values(array_filter($ids, fn (int $id): bool => $this->enabled($id, PayrollModule::KEY))));

            $rows = $companies->map(function (Company $company) use ($drafts, $toApprove, $toPay, $overdue, $lastPosted, $years, $reported, $payroll, $today): array {
                $id = $company->id;
                /** @var Collection<int, FiscalYear> $companyYears */
                $companyYears = $years->get($id, collect());
                $tasks = array_values(array_filter([
                    $this->task($id, 'vat:prepare', 'vat', ...$this->vat($company, $companyYears, $reported[$id] ?? [], $today)),
                    $this->task($id, 'payroll:agi-submit', 'agi', $payroll['agi'][$id] ?? 0, __('AGI att lämna'), true),
                    $this->task($id, 'fiscal-years:manage', 'fiscal-years', $companyYears->filter(fn (FiscalYear $year): bool => $year->status === FiscalYearStatus::Open && $year->end_date->lessThan($today))->count(), __('Räkenskapsår att stänga'), false),
                    $this->task($id, 'invoices:view', 'invoices', $overdue[$id] ?? 0, __('Förfallna kundfakturor'), true),
                    $this->task($id, 'supplier-invoices:approve', 'supplier-invoices', $toApprove[$id] ?? 0, __('Leverantörsfakturor att attestera'), false),
                    $this->task($id, 'payments:create', 'supplier-invoices', $toPay[$id] ?? 0, __('Att betala inom en vecka'), false),
                    $this->task($id, 'vouchers:create', 'vouchers', $drafts[$id] ?? 0, __('Verifikationer i utkast'), false),
                    $this->task($id, 'payroll:approve', 'payroll-runs', $payroll['runs'][$id] ?? 0, __('Lönekörningar att attestera'), false),
                    $this->task($id, 'payroll:run', 'payroll-requests', $payroll['requests'][$id] ?? 0, __('Ansökningar och utlägg att besvara'), false),
                ]));

                return [
                    'company' => $company,
                    'role' => $this->roles[$id] === null ? 'owner' : (string) $this->roles[$id],
                    'tasks' => $tasks,
                    'last_posted' => $this->may($id, 'vouchers:view') && isset($lastPosted[$id]) ? CarbonImmutable::parse((string) $lastPosted[$id])->toDateString() : null,
                    'attention' => array_sum(array_column($tasks, 'count')),
                ];
            });

            return array_values($rows->sortBy([
                fn (array $a, array $b): int => (int) collect($b['tasks'])->contains('urgent', true) <=> (int) collect($a['tasks'])->contains('urgent', true),
                fn (array $a, array $b): int => $b['attention'] <=> $a['attention'],
                fn (array $a, array $b): int => strnatcasecmp($a['company']->name, $b['company']->name),
            ])->all());
        });
    }

    /**
     * VAT periods that have ended without a return in the company's open
     * fiscal years: how many, and the oldest in the label.
     *
     * @param  Collection<int, FiscalYear>  $years
     * @param  array<int, string>  $reported  period start dates
     * @return array{0: int, 1: string, 2: bool}
     */
    private function vat(Company $company, Collection $years, array $reported, CarbonImmutable $today): array
    {
        if (! $company->vat_registered) {
            return [0, '', false];
        }

        $missing = [];

        foreach ($years->filter(fn (FiscalYear $year): bool => $year->status === FiscalYearStatus::Open) as $year) {
            foreach (VatPeriods::of($year, $company->vat_period) as $period) {
                if ($period['end']->lessThan($today) && ! in_array($period['start']->toDateString(), $reported, true)) {
                    $missing[] = $period;
                }
            }
        }

        if ($missing === []) {
            return [0, '', false];
        }

        return [count($missing), __('Moms att redovisa, äldsta :from – :to', ['from' => $missing[0]['start']->toDateString(), 'to' => $missing[0]['end']->toDateString()]), true];
    }

    /**
     * Months paid out in approved runs without a submitted employer
     * declaration, runs waiting for approval, and requests and outlays
     * waiting for an answer, per company.
     *
     * @param  list<int>  $ids  companies with payroll turned on
     * @return array{agi: array<int, int>, runs: array<int, int>, requests: array<int, int>}
     */
    private function payroll(array $ids): array
    {
        if ($ids === []) {
            return ['agi' => [], 'runs' => [], 'requests' => []];
        }

        $paid = PayrollRun::query()->whereIn('company_id', $ids)->whereIn('status', [PayrollRun::APPROVED, PayrollRun::PAID, PayrollRun::POSTED])
            ->select('company_id', DB::raw("to_char(payment_date, 'YYYY-MM') as month"))->distinct()->get();
        $declared = AgiSubmission::query()->whereIn('company_id', $ids)->whereIn('status', AgiSubmission::REPORTED)
            ->select('company_id', 'period')->distinct()->get()->map(fn (AgiSubmission $submission): string => "{$submission->company_id}:{$submission->period}")->all();
        $agi = [];

        foreach ($paid as $run) {
            $key = "{$run->company_id}:{$run->getAttribute('month')}";

            if (! in_array($key, $declared, true)) {
                $agi[$run->company_id] = ($agi[$run->company_id] ?? 0) + 1;
            }
        }

        $requests = $this->countBy(Absence::query()->whereIn('company_id', $ids)->where('status', 'REQUESTED'));

        foreach ($this->countBy(Expense::query()->whereIn('company_id', $ids)->where('status', 'SUBMITTED')->whereNotNull('requested_by')) as $id => $count) {
            $requests[$id] = ($requests[$id] ?? 0) + $count;
        }

        return [
            'agi' => $agi,
            'runs' => $this->countBy(PayrollRun::query()->whereIn('company_id', $ids)->where('status', PayrollRun::CALCULATED)),
            'requests' => $requests,
        ];
    }

    /**
     * @return array{key: string, label: string, count: int, destination: string, urgent: bool}|null
     */
    private function task(int $companyId, string $permission, string $destination, int $count, string $label, bool $urgent): ?array
    {
        if ($count === 0 || ! $this->may($companyId, $permission)) {
            return null;
        }

        return ['key' => "{$destination}:{$label}", 'label' => $label, 'count' => $count, 'destination' => $destination, 'urgent' => $urgent];
    }

    /**
     * The user's permission in a company, from the memberships already
     * loaded; same rules as User::hasTeamPermission, without a query per
     * company.
     */
    private function may(int $companyId, string $permission): bool
    {
        $module = app(ModuleRegistry::class)->moduleForPermission($permission);

        if ($module !== null && ! $this->enabled($companyId, $module->key())) {
            return false;
        }

        $role = $this->roles[$companyId] ?? null;

        if ($role === null) {
            return $module === null || in_array($permission, $module->rolePermissions()['owner'] ?? [], true);
        }

        $permissions = isset(Jetstream::$roles[$role]) ? Jetstream::$roles[$role]->permissions : [];

        return in_array($permission, $permissions, true) || in_array('*', $permissions, true);
    }

    private function enabled(int $companyId, string $module): bool
    {
        return in_array($module, $this->modules[$companyId] ?? [], true);
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<int, int>
     */
    private function countBy($query): array
    {
        return $query->groupBy('company_id')->select('company_id', DB::raw('count(*) as aggregate'))->pluck('aggregate', 'company_id')
            ->map(fn (mixed $count): int => (int) $count)->all();
    }
}
