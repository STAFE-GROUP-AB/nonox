<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Reports;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\VoucherRow;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Models\PensionPlan;
use App\Modules\Payroll\Support\Agi\AgiDeclaration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The payroll reports (PAYROLL_MODULE.md B.14) over approved runs, by the
 * month the pay is paid out, as for the arbetsgivardeklaration. Each report
 * reads its data with a fixed number of queries. Runs in the current
 * company's scope.
 */
final class PayrollReports
{
    /** Salary tax and contribution accounts compared with the declarations. */
    public const int TAX_ACCOUNT = 2710;

    public const int CONTRIBUTIONS_COST_ACCOUNT = 7510;

    /**
     * Lönejournal: every payslip, run by run.
     *
     * @return list<array{run: string, payment_date: string, number: int, name: string, gross_ore: int, tax_ore: int, net_ore: int, contributions_ore: int}>
     */
    public function journal(string $from, string $to): array
    {
        $runs = $this->runs($from, $to)->keyBy('id');
        $rows = [];

        foreach (Payslip::query()->with('employee')->whereIn('payroll_run_id', $runs->keys())->get() as $payslip) {
            /** @var PayrollRun $run */
            $run = $runs->get($payslip->payroll_run_id);
            $rows[] = [
                'run' => $run->title(),
                'payment_date' => $run->payment_date->toDateString(),
                'number' => (int) $payslip->employee?->employee_number,
                'name' => trim("{$payslip->employee?->first_name} {$payslip->employee?->last_name}"),
                'gross_ore' => $payslip->gross_ore->ore,
                'tax_ore' => $payslip->tax_ore->ore,
                'net_ore' => $payslip->net_ore->ore,
                'contributions_ore' => $payslip->employer_contribution_ore->ore,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['payment_date'], $a['number']] <=> [$b['payment_date'], $b['number']]);

        return $rows;
    }

    /**
     * Lönelista per löneart: amount and number of lines per pay code.
     *
     * @return list<array{code: string, name: string, lines: int, amount_ore: int}>
     */
    public function payCodes(string $from, string $to): array
    {
        $sums = PayslipLine::query()
            ->whereIn('payslip_id', Payslip::query()->whereIn('payroll_run_id', $this->runs($from, $to)->pluck('id'))->select('id'))
            ->groupBy('pay_code_id')
            ->selectRaw('pay_code_id, count(*) as lines, sum(amount_ore) as amount')
            ->get();
        $codes = PayCode::query()->whereIn('id', $sums->pluck('pay_code_id'))->get()->keyBy('id');

        return array_values($sums->map(fn (PayslipLine $sum): array => [
            'code' => (string) $codes->get($sum->pay_code_id)?->code,
            'name' => (string) $codes->get($sum->pay_code_id)?->name_sv,
            'lines' => (int) $sum->getAttribute('lines'),
            'amount_ore' => (int) $sum->getAttribute('amount'),
        ])->sortBy('code')->values()->all());
    }

    /**
     * Hittills i år per anställd: the calendar year's payslips summed.
     *
     * @return list<array{number: int, name: string, gross_ore: int, tax_ore: int, net_ore: int, contributions_ore: int}>
     */
    public function yearToDate(int $year): array
    {
        $payslips = Payslip::query()->whereIn('payroll_run_id', $this->runs("{$year}-01", "{$year}-12")->pluck('id'))->get()->groupBy('employee_id');
        $employees = Employee::query()->whereIn('id', $payslips->keys())->get()->keyBy('id');

        return array_values($payslips->map(fn (Collection $rows, int $employeeId): array => [
            'number' => (int) $employees->get($employeeId)?->employee_number,
            'name' => trim("{$employees->get($employeeId)?->first_name} {$employees->get($employeeId)?->last_name}"),
            'gross_ore' => (int) $rows->sum(fn (Payslip $payslip): int => $payslip->gross_ore->ore),
            'tax_ore' => (int) $rows->sum(fn (Payslip $payslip): int => $payslip->tax_ore->ore),
            'net_ore' => (int) $rows->sum(fn (Payslip $payslip): int => $payslip->net_ore->ore),
            'contributions_ore' => (int) $rows->sum(fn (Payslip $payslip): int => $payslip->employer_contribution_ore->ore),
        ])->sortBy('number')->values()->all());
    }

    /**
     * Kostnad per kostnadsställe: gross pay and contributions by the
     * employment's cost center.
     *
     * @return list<array{code: string, name: string, gross_ore: int, contributions_ore: int}>
     */
    public function costCenters(string $from, string $to): array
    {
        $payslips = Payslip::query()->whereIn('payroll_run_id', $this->runs($from, $to)->pluck('id'))->get();
        $centerOf = Employment::query()->whereIn('id', $payslips->pluck('employment_id'))->pluck('cost_center_id', 'id');
        $centers = CostCenter::query()->whereIn('id', $centerOf->filter()->unique())->get()->keyBy('id');

        return array_values($payslips->groupBy(fn (Payslip $payslip): string => (string) $centerOf->get($payslip->employment_id))
            ->map(fn (Collection $rows, string $centerId): array => [
                'code' => (string) $centers->get((int) $centerId)?->code,
                'name' => $centerId === '' ? __('Utan kostnadsställe') : (string) $centers->get((int) $centerId)?->name,
                'gross_ore' => (int) $rows->sum(fn (Payslip $payslip): int => $payslip->gross_ore->ore),
                'contributions_ore' => (int) $rows->sum(fn (Payslip $payslip): int => $payslip->employer_contribution_ore->ore),
            ])->sortBy('code')->values()->all());
    }

    /**
     * Pension per plan: premiums and särskild löneskatt by the employment's
     * pension plan.
     *
     * @return list<array{plan: string, premium_ore: int, special_tax_ore: int}>
     */
    public function pension(string $from, string $to): array
    {
        $codes = PayCode::query()->whereIn('code', ['TJANSTEPENSION', 'SARSKILD_LONESKATT'])->pluck('code', 'id');
        $payslips = Payslip::query()->whereIn('payroll_run_id', $this->runs($from, $to)->pluck('id'))->get(['id', 'employment_id'])->keyBy('id');
        $lines = PayslipLine::query()->whereIn('payslip_id', $payslips->keys())->whereIn('pay_code_id', $codes->keys())->get();
        $planOf = Employment::query()->whereIn('id', $payslips->pluck('employment_id'))->pluck('pension_plan_id', 'id');
        $plans = PensionPlan::query()->whereIn('id', $planOf->filter()->unique())->pluck('name', 'id');

        return array_values($lines->groupBy(fn (PayslipLine $line): string => (string) $planOf->get($payslips->get($line->payslip_id)?->employment_id))
            ->map(fn (Collection $rows, string $planId): array => [
                'plan' => $planId === '' ? __('Utan pensionsplan') : (string) $plans->get((int) $planId),
                'premium_ore' => (int) $rows->filter(fn (PayslipLine $line): bool => $codes->get($line->pay_code_id) === 'TJANSTEPENSION')->sum(fn (PayslipLine $line): int => $line->amount_ore->ore),
                'special_tax_ore' => (int) $rows->filter(fn (PayslipLine $line): bool => $codes->get($line->pay_code_id) === 'SARSKILD_LONESKATT')->sum(fn (PayslipLine $line): int => $line->amount_ore->ore),
            ])->sortBy('plan')->values()->all());
    }

    /**
     * Avstämning per månad: tax and contributions on the payslips, in the
     * latest declaration Skatteverket has, and booked by the salary vouchers
     * (2710 credited, 7510 debited).
     *
     * @return list<array{period: string, payslip_tax_ore: int, payslip_contributions_ore: int, declared_tax_ore: ?int, declared_contributions_ore: ?int, booked_tax_ore: ?int, booked_contributions_ore: ?int}>
     */
    public function reconciliation(int $year): array
    {
        $runs = $this->runs("{$year}-01", "{$year}-12");
        $payslips = Payslip::query()->whereIn('payroll_run_id', $runs->pluck('id'))->get(['payroll_run_id', 'tax_ore', 'employer_contribution_ore'])->groupBy('payroll_run_id');
        $declared = AgiSubmission::query()->where('period', 'like', "{$year}-%")->whereIn('status', AgiSubmission::REPORTED)->orderByDesc('sequence')->get()->unique('period')->keyBy('period');
        $accounts = Account::query()->whereIn('number', [self::TAX_ACCOUNT, self::CONTRIBUTIONS_COST_ACCOUNT])->pluck('number', 'id');
        $booked = VoucherRow::query()->whereIn('voucher_id', $runs->pluck('posted_voucher_id')->filter())->whereIn('account_id', $accounts->keys())->get()->groupBy('voucher_id');
        $rows = [];

        foreach ($runs->groupBy(fn (PayrollRun $run): string => $run->payment_date->format('Y-m'))->sortKeys() as $period => $periodRuns) {
            $slips = $periodRuns->flatMap(fn (PayrollRun $run): Collection => $payslips->get($run->id, collect()));
            $posted = $periodRuns->every(fn (PayrollRun $run): bool => $run->posted_voucher_id !== null);
            $voucherRows = $periodRuns->flatMap(fn (PayrollRun $run): Collection => $booked->get((string) $run->posted_voucher_id, collect()));
            $submission = $declared->get($period);

            $rows[] = [
                'period' => (string) $period,
                'payslip_tax_ore' => (int) $slips->sum(fn (Payslip $payslip): int => $payslip->tax_ore->ore),
                'payslip_contributions_ore' => (int) $slips->sum(fn (Payslip $payslip): int => $payslip->employer_contribution_ore->ore),
                'declared_tax_ore' => $submission?->tax_ore,
                'declared_contributions_ore' => $submission?->contributions_ore,
                'booked_tax_ore' => $posted ? (int) $voucherRows->filter(fn (VoucherRow $row): bool => $accounts->get($row->account_id) === self::TAX_ACCOUNT)->sum(fn (VoucherRow $row): int => $row->credit_ore->ore - $row->debit_ore->ore) : null,
                'booked_contributions_ore' => $posted ? (int) $voucherRows->filter(fn (VoucherRow $row): bool => $accounts->get($row->account_id) === self::CONTRIBUTIONS_COST_ACCOUNT)->sum(fn (VoucherRow $row): int => $row->debit_ore->ore - $row->credit_ore->ore) : null,
            ];
        }

        return $rows;
    }

    /** @return Collection<int, PayrollRun> */
    private function runs(string $from, string $to): Collection
    {
        return PayrollRun::query()
            ->whereIn('status', AgiDeclaration::DECLARED_STATUSES)
            ->whereBetween('payment_date', [CarbonImmutable::parse("{$from}-01")->toDateString(), CarbonImmutable::parse("{$to}-01")->endOfMonth()->toDateString()])
            ->orderBy('payment_date')
            ->get();
    }
}
