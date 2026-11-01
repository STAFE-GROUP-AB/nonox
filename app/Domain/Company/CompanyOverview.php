<?php

declare(strict_types=1);

namespace App\Domain\Company;

use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Domain\Vat\VatDeadlines;
use App\Domain\Vat\VatPeriods;
use App\Models\BankTransaction;
use App\Models\ClientProfile;
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
use App\Modules\Payroll\Models\PayrollRuleVerification;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Agi\AgiDeadlines;
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

    /** A deadline this close counts as urgent. */
    private const int URGENT_DAYS = 7;

    /** @var array<int, array<int, string>> enabled module keys per company */
    private array $modules = [];

    /** @var array<int, array<string, mixed>> payroll settings per company */
    private array $payrollSettings = [];

    /** @var Collection<int, PayrollRuleVersion> */
    private Collection $agiRules;

    /** @var array<int, array<int, int>> rule versions each company verified itself */
    private array $agiVerified = [];

    private CarbonImmutable $today;

    /** @var array<int, ?string> the user's role per company, null for the owner */
    private array $roles = [];

    /**
     * @return list<array{
     *     company: Company,
     *     role: string,
     *     tasks: list<array{key: string, permission: string, label: string, count: int, destination: string, urgent: bool, due: ?string, overdue: bool}>,
     *     last_posted: ?string,
     *     attention: int,
     *     responsible: ?array{id: int, name: string},
     *     consultants: array<int, string>,
     *     note: ?string,
     *     can_assign: bool,
     *     can_note: bool
     * }>
     */
    public function for(User $user, ?CarbonImmutable $today = null, ?Company $only = null): array
    {
        $today ??= CarbonImmutable::today();
        $companies = $this->prepare($user, $today);

        // Översikt asks for one company only (App\Domain\Overview\Todos\CompanyTasks).
        if ($only !== null) {
            $companies = $companies->where('id', $only->id)->values();
        }

        $ids = array_values($companies->pluck('id')->all());

        if ($ids === []) {
            return [];
        }

        // Read across the user's own companies at once; each id comes from their memberships.
        return CompanyScope::bypass(function () use ($companies, $ids, $today): array {
            $this->loadModules($ids);

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
            $profiles = ClientProfile::query()->whereIn('company_id', $ids)->get()->keyBy('company_id');
            $consultants = DB::table('team_user')->join('users', 'users.id', '=', 'team_user.user_id')
                ->whereIn('team_user.team_id', $ids)->where('team_user.role', ClientProfile::FIRM_ROLE)
                ->orderBy('users.name')->get(['team_user.team_id', 'users.id', 'users.name'])
                ->groupBy('team_id')->map(fn (Collection $rows): array => $rows->mapWithKeys(fn (object $row): array => [(int) $row->id => (string) $row->name])->all())->all();

            $rows = $companies->map(function (Company $company) use ($drafts, $toApprove, $toPay, $overdue, $lastPosted, $years, $reported, $payroll, $today, $profiles, $consultants): array {
                $id = $company->id;
                /** @var Collection<int, FiscalYear> $companyYears */
                $companyYears = $years->get($id, collect());
                $tasks = array_values(array_filter([
                    $this->task($id, 'vat:prepare', 'vat', ...$this->vat($company, $companyYears, $reported[$id] ?? [], $today)),
                    $this->task($id, 'payroll:agi-submit', 'agi', count($payroll['agi'][$id] ?? []), __('AGI att lämna, äldsta :month', ['month' => $payroll['agi'][$id][0] ?? '']), true, $this->agiDeadline($id, $payroll['agi'][$id][0] ?? null)),
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
                    // Nothing waits in an archived company: it can only be read (ADR-024).
                    'tasks' => $company->isArchived() ? [] : $tasks,
                    'last_posted' => $this->may($id, 'vouchers:view') && isset($lastPosted[$id]) ? CarbonImmutable::parse((string) $lastPosted[$id])->toDateString() : null,
                    'attention' => array_sum(array_column($tasks, 'count')),
                    ...$this->clientProfile($id, $profiles->get($id), $consultants[$id] ?? []),
                ];
            });

            $firstDue = fn (array $row): string => (string) (min(array_filter(array_column($row['tasks'], 'due')) ?: ['9999-12-31']));

            return array_values($rows->sortBy([
                fn (array $a, array $b): int => (int) collect($b['tasks'])->contains('urgent', true) <=> (int) collect($a['tasks'])->contains('urgent', true),
                fn (array $a, array $b): int => $firstDue($a) <=> $firstDue($b),
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
     * @return array{0: int, 1: string, 2: bool, 3: ?CarbonImmutable}
     */
    private function vat(Company $company, Collection $years, array $reported, CarbonImmutable $today): array
    {
        if (! $company->vat_registered) {
            return [0, '', false, null];
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
            return [0, '', false, null];
        }

        return [
            count($missing),
            __('Moms att redovisa, äldsta :from – :to', ['from' => $missing[0]['start']->toDateString(), 'to' => $missing[0]['end']->toDateString()]),
            true,
            VatDeadlines::for($company->vat_period, $missing[0]['end'], $company->turnover_over_40_msek),
        ];
    }

    /**
     * Months paid out in approved runs without a submitted employer
     * declaration, runs waiting for approval, and requests and outlays
     * waiting for an answer, per company.
     *
     * @param  list<int>  $ids  companies with payroll turned on
     * @return array{agi: array<int, list<string>>, runs: array<int, int>, requests: array<int, int>}
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

        foreach ($paid->sortBy(fn (PayrollRun $run): string => (string) $run->getAttribute('month')) as $run) {
            $month = (string) $run->getAttribute('month');

            if (! in_array("{$run->company_id}:{$month}", $declared, true)) {
                $agi[$run->company_id][] = $month;
            }
        }

        $this->loadAgiRules($ids);

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
     * Months with where each company stands, the way a firm follows its
     * clients: posted up to which day, vouchers posted, the month locked,
     * bank payments not yet matched, the VAT period and the employer
     * declaration (REPORTED, DUE, LATE, OPEN; for VAT also QUARTER or YEAR
     * when no period ends in the month, NONE when the company has no VAT or
     * payroll for it), and what customers still owe on invoices issued by
     * the end of the month.
     *
     * @return array{
     *     months: list<string>,
     *     rows: list<array{
     *         company: Company,
     *         cells: array<string, array{posted: ?int, posted_until: ?string, locked: bool, bank: ?int, receivables: ?int, vat: ?string, vat_due: ?string, agi: ?string, agi_due: ?string}>
     *     }>
     * }
     */
    public function months(User $user, int $back = 5, int $ahead = 0, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $companies = $this->prepare($user, $today);
        $ids = array_values($companies->pluck('id')->all());
        $first = $today->startOfMonth()->subMonthsNoOverflow($back);
        $months = array_map(fn (int $i): string => $first->addMonthsNoOverflow($i)->format('Y-m'), range(0, $back + $ahead));
        $last = CarbonImmutable::parse(end($months).'-01')->endOfMonth();

        if ($ids === []) {
            return ['months' => $months, 'rows' => []];
        }

        return CompanyScope::bypass(function () use ($companies, $ids, $months, $first, $last, $today): array {
            $this->loadModules($ids);
            $payrollIds = array_values(array_filter($ids, fn (int $id): bool => $this->enabled($id, PayrollModule::KEY)));
            $this->loadAgiRules($payrollIds);

            $from = $first->toDateString();
            $postedRows = Voucher::query()->whereIn('company_id', $ids)->where('status', VoucherStatus::Posted->value)
                ->whereDate('voucher_date', '<=', $last->toDateString())
                ->selectRaw("company_id, case when voucher_date < ? then 'earlier' else to_char(voucher_date, 'YYYY-MM') end as month, count(*) as aggregate, max(voucher_date) as last", [$from])
                ->groupByRaw('1, 2')->get();
            $posted = $postedRows->mapWithKeys(fn (Voucher $voucher): array => ["{$voucher->company_id}:{$voucher->getAttribute('month')}" => (int) $voucher->getAttribute('aggregate')])->all();
            $postedUntil = $postedRows->mapWithKeys(fn (Voucher $voucher): array => ["{$voucher->company_id}:{$voucher->getAttribute('month')}" => CarbonImmutable::parse((string) $voucher->getAttribute('last'))->toDateString()])->all();
            $bank = BankTransaction::query()->whereIn('company_id', $ids)->whereNull('invoice_id')->whereDate('booking_date', '<=', $last->toDateString())
                ->selectRaw("company_id, case when booking_date < ? then 'earlier' else to_char(booking_date, 'YYYY-MM') end as month, count(*) as aggregate", [$from])
                ->groupByRaw('1, 2')->get()
                ->mapWithKeys(fn (BankTransaction $row): array => ["{$row->company_id}:{$row->getAttribute('month')}" => (int) $row->getAttribute('aggregate')])->all();
            $hasBank = BankTransaction::query()->whereIn('company_id', $ids)->distinct()->pluck('company_id')->map(fn (mixed $id): int => (int) $id)->all();
            $receivables = Invoice::query()->whereIn('company_id', $ids)->where('status', InvoiceStatus::Issued->value)->whereDate('invoice_date', '<=', $last->toDateString())
                ->selectRaw("company_id, case when invoice_date < ? then 'earlier' else to_char(invoice_date, 'YYYY-MM') end as month, sum(total_ore - paid_ore) as aggregate", [$from])
                ->groupByRaw('1, 2')->get()
                ->mapWithKeys(fn (Invoice $row): array => ["{$row->company_id}:{$row->getAttribute('month')}" => (int) $row->getAttribute('aggregate')])->all();
            $years = FiscalYear::query()->whereIn('company_id', $ids)->orderBy('start_date')->get()->groupBy('company_id');
            $reported = VatReport::query()->whereIn('company_id', $ids)->get(['company_id', 'period_start'])
                ->map(fn (VatReport $report): string => "{$report->company_id}:{$report->period_start->toDateString()}")->all();
            $paid = $payrollIds === [] ? [] : PayrollRun::query()->whereIn('company_id', $payrollIds)->whereIn('status', [PayrollRun::APPROVED, PayrollRun::PAID, PayrollRun::POSTED])
                ->select('company_id', DB::raw("to_char(payment_date, 'YYYY-MM') as month"))->distinct()->get()
                ->map(fn (PayrollRun $run): string => "{$run->company_id}:{$run->getAttribute('month')}")->all();
            $declared = $payrollIds === [] ? [] : AgiSubmission::query()->whereIn('company_id', $payrollIds)->whereIn('status', AgiSubmission::REPORTED)
                ->select('company_id', 'period')->distinct()->get()->map(fn (AgiSubmission $submission): string => "{$submission->company_id}:{$submission->period}")->all();

            $rows = $companies->map(function (Company $company) use ($months, $posted, $postedUntil, $bank, $hasBank, $receivables, $years, $reported, $paid, $declared, $today, $payrollIds): array {
                $id = $company->id;
                $seesLedger = $this->may($id, 'reports:view');
                $seesInvoices = $this->may($id, 'invoices:view');
                $seesPayroll = $this->may($id, 'payroll:view');
                $hasPayroll = in_array($id, $payrollIds, true);
                $vatPeriods = [];

                if ($company->vat_registered && $seesLedger) {
                    foreach ($years->get($id, collect()) as $year) {
                        foreach (VatPeriods::of($year, $company->vat_period) as $period) {
                            $vatPeriods[$period['end']->format('Y-m')] = $period;
                        }
                    }
                }

                $cells = [];
                // Totals carried from before the first month: "earlier" sorts before every YYYY-MM.
                $until = $postedUntil["{$id}:earlier"] ?? null;
                $openBank = $bank["{$id}:earlier"] ?? 0;
                $owed = $receivables["{$id}:earlier"] ?? 0;

                foreach ($months as $month) {
                    $until = $postedUntil["{$id}:{$month}"] ?? $until;
                    $openBank += $bank["{$id}:{$month}"] ?? 0;
                    $owed += $receivables["{$id}:{$month}"] ?? 0;

                    $period = $vatPeriods[$month] ?? null;
                    $vatDue = $period === null ? null : VatDeadlines::for($company->vat_period, $period['end'], $company->turnover_over_40_msek);
                    $agiDue = $seesPayroll && in_array("{$id}:{$month}", $paid, true) ? $this->agiDeadline($id, $month) : null;
                    $monthEnd = CarbonImmutable::parse("{$month}-01")->endOfMonth()->startOfDay();

                    $cells[$month] = [
                        'posted' => $seesLedger ? ($posted["{$id}:{$month}"] ?? 0) : null,
                        'posted_until' => $seesLedger ? $until : null,
                        'bank' => $seesLedger && in_array($id, $hasBank, true) ? $openBank : null,
                        'receivables' => $seesInvoices ? $owed : null,
                        'locked' => $company->locked_until_date !== null && $company->locked_until_date->greaterThanOrEqualTo($monthEnd),
                        'vat' => ! $seesLedger ? null : match (true) {
                            ! $company->vat_registered => 'NONE',
                            $period === null => $company->vat_period === VatPeriod::Yearly ? 'YEAR' : 'QUARTER',
                            in_array("{$id}:{$period['start']->toDateString()}", $reported, true) => 'REPORTED',
                            $period['end']->greaterThanOrEqualTo($today) => 'OPEN',
                            $vatDue !== null && $vatDue->lessThan($today) => 'LATE',
                            default => 'DUE',
                        },
                        'vat_due' => $vatDue?->toDateString(),
                        'agi' => ! $seesPayroll ? ($hasPayroll ? null : 'NONE') : match (true) {
                            ! in_array("{$id}:{$month}", $paid, true) => 'NONE',
                            in_array("{$id}:{$month}", $declared, true) => 'REPORTED',
                            $agiDue !== null && $agiDue->lessThan($today) => 'LATE',
                            default => 'DUE',
                        },
                        'agi_due' => $agiDue?->toDateString(),
                    ];
                }

                return ['company' => $company, 'cells' => $cells];
            });

            return ['months' => $months, 'rows' => array_values($rows->sortBy(fn (array $row): string => mb_strtolower($row['company']->name))->all())];
        });
    }

    /**
     * The responsible consultant for everyone, and the firm's note only for
     * the firm's consultants.
     *
     * @param  array<int, string>  $consultants
     * @return array{responsible: ?array{id: int, name: string}, consultants: array<int, string>, note: ?string, can_assign: bool, can_note: bool}
     */
    private function clientProfile(int $companyId, ?ClientProfile $profile, array $consultants): array
    {
        $role = $this->roles[$companyId] ?? null;
        $isConsultant = $role === ClientProfile::FIRM_ROLE;
        $responsibleId = $profile?->responsible_user_id;
        $canAssign = $isConsultant || $role === null || $role === 'admin';

        return [
            'responsible' => $responsibleId !== null && isset($consultants[$responsibleId]) ? ['id' => $responsibleId, 'name' => $consultants[$responsibleId]] : null,
            'consultants' => $canAssign ? $consultants : [],
            'note' => $isConsultant ? $profile?->note : null,
            'can_assign' => $canAssign && $consultants !== [],
            'can_note' => $isConsultant,
        ];
    }

    /**
     * The user's companies, with the user's role in each remembered.
     *
     * @return Collection<int, Company>
     */
    private function prepare(User $user, CarbonImmutable $today): Collection
    {
        $this->today = $today;
        $this->agiRules = collect();
        $this->roles = [];
        $companies = $user->companies();

        foreach ($companies as $company) {
            $this->roles[$company->id] = $user->ownsTeam($company) ? null : (string) $company->getRelationValue('membership')?->getAttribute('role');
        }

        return $companies;
    }

    /**
     * @param  list<int>  $ids
     */
    private function loadModules(array $ids): void
    {
        $modules = CompanyModule::query()->whereIn('company_id', $ids)->where('enabled', true)->get(['company_id', 'module_key', 'settings']);
        $this->modules = $modules->groupBy('company_id')->map(fn (Collection $rows): array => $rows->pluck('module_key')->all())->all();
        $this->payrollSettings = $modules->where('module_key', PayrollModule::KEY)->mapWithKeys(fn (CompanyModule $module): array => [$module->company_id => (array) $module->settings])->all();
    }

    /**
     * @param  list<int>  $ids  companies with payroll turned on
     */
    private function loadAgiRules(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->agiRules = PayrollRuleVersion::query()->where('key', 'agi.deadlines')->orderBy('valid_from')->get();
        $this->agiVerified = PayrollRuleVerification::query()->whereIn('company_id', $ids)->whereIn('payroll_rule_version_id', $this->agiRules->modelKeys())
            ->get(['company_id', 'payroll_rule_version_id'])->groupBy('company_id')
            ->map(fn (Collection $rows): array => $rows->pluck('payroll_rule_version_id')->map(fn (mixed $id): int => (int) $id)->values()->all())->all();
    }

    /**
     * The last day to declare a payment month, from the agi.deadlines rule
     * verified for the installation or by the company itself (as RuleSet
     * reads it), without a query per company. Null without a checked rule.
     */
    private function agiDeadline(int $companyId, ?string $month): ?CarbonImmutable
    {
        if ($month === null) {
            return null;
        }

        $declared = AgiDeadlines::declarationMonth($month);
        $rule = $this->agiRules
            ->filter(fn (PayrollRuleVersion $version): bool => $version->valid_from->lessThanOrEqualTo($declared)
                && ($version->valid_to === null || $version->valid_to->greaterThanOrEqualTo($declared))
                && ($version->isVerified() || in_array($version->id, $this->agiVerified[$companyId] ?? [], true)))
            ->last();

        if (! $rule instanceof PayrollRuleVersion) {
            return null;
        }

        return AgiDeadlines::fromRule((array) $rule->value, $month, (string) ($this->payrollSettings[$companyId]['agi_deadline_group'] ?? AgiDeadlines::SMALL));
    }

    /**
     * @return array{key: string, permission: string, label: string, count: int, destination: string, urgent: bool, due: ?string, overdue: bool}|null
     */
    private function task(int $companyId, string $permission, string $destination, int $count, string $label, bool $urgent, ?CarbonImmutable $due = null): ?array
    {
        if ($count === 0 || ! $this->may($companyId, $permission)) {
            return null;
        }

        return [
            'key' => "{$destination}:{$label}",
            'permission' => $permission,
            'label' => $label,
            'count' => $count,
            'destination' => $destination,
            'urgent' => $urgent || ($due !== null && $due->lessThanOrEqualTo($this->today->addDays(self::URGENT_DAYS))),
            'due' => $due?->toDateString(),
            'overdue' => $due !== null && $due->lessThan($this->today),
        ];
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
