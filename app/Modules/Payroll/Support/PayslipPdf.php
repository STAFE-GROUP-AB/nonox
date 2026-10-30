<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support;

use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Domain\PersonalNumber;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Vacation\VacationLedger;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The payslip as a PDF (PAYROLL_MODULE.md B.11) in our own layout: the
 * employer, period, payment date, every line with quantity, unit and rate,
 * gross, tax with table and column, net, year-to-date totals, the employer's
 * contributions and a masked bank account. Year-to-date comes from the
 * approved payslips of the year up to this one, so an old payslip always
 * looks as it did when it was issued. A payslip in a run that is not yet
 * approved is a preview and says so on every page.
 */
final class PayslipPdf
{
    public static function render(Payslip $payslip): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(self::html($payslip));
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    public static function html(Payslip $payslip): string
    {
        $payslip->loadMissing(['lines', 'employee', 'employment']);
        $run = PayrollRun::query()->findOrFail($payslip->payroll_run_id);
        $company = Company::query()->findOrFail($payslip->company_id);
        /** @var Employee $employee */
        $employee = $payslip->employee;
        $employment = $payslip->employment;
        $codes = PayCode::query()->whereIn('id', $payslip->lines->pluck('pay_code_id'))->pluck('unit', 'id');

        $earnings = $payslip->lines->filter(fn (PayslipLine $line): bool => $line->tax_effect !== PayslipLineResult::NET);
        $afterTax = $payslip->lines->filter(fn (PayslipLine $line): bool => $line->tax_effect === PayslipLineResult::NET);

        $earlier = Payslip::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->where('payslips.employee_id', $payslip->employee_id)
            ->whereNotIn('payroll_runs.status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
            ->whereYear('payroll_runs.payment_date', $run->payment_date->year)
            ->whereDate('payroll_runs.payment_date', '<=', $run->payment_date->toDateString())
            ->selectRaw('COALESCE(SUM(payslips.gross_ore), 0) AS gross, COALESCE(SUM(payslips.tax_ore), 0) AS tax, COALESCE(SUM(payslips.net_ore), 0) AS net')
            ->toBase()
            ->first();

        $contribution = $payslip->contribution_explanation ?? [];
        $vacation = self::vacation($payslip, $employee, $run, $company);

        return view('payroll::payslip-pdf', [
            'payslip' => $payslip,
            'run' => $run,
            'company' => $company,
            'employee' => $employee,
            'personalNumber' => PersonalNumber::masked((string) $employee->personal_number),
            'salary' => self::salary($employment),
            'preview' => $run->isOpen(),
            'earnings' => $earnings->map(fn (PayslipLine $line): array => self::row($line, (string) ($codes[$line->pay_code_id] ?? '')))->values()->all(),
            'earningsTotal' => $earnings->sum(fn (PayslipLine $line): int => $line->amount_ore->ore),
            'afterTax' => $afterTax->map(fn (PayslipLine $line): array => self::row($line, (string) ($codes[$line->pay_code_id] ?? '')))->values()->all(),
            'ytd' => ['gross' => (int) ($earlier->gross ?? 0), 'tax' => (int) ($earlier->tax ?? 0), 'net' => (int) ($earlier->net ?? 0)],
            'contributionRates' => array_values(array_unique(array_map(fn (mixed $part): int => (int) ((array) $part)['rate_bp'], (array) ($contribution['parts'] ?? [])))),
            'account' => self::maskedAccount($employee),
            'vacation' => $vacation,
        ])->render();
    }

    public static function filename(Payslip $payslip, PayrollRun $run): string
    {
        return "Lonebesked-{$run->period}-{$payslip->employee_id}.pdf";
    }

    /**
     * A line as the payslip shows it: hours and the hourly value also for
     * absence and sick pay, where the engine keeps them in the explanation.
     *
     * @return array{label: string, quantity: ?string, unit: ?string, rate: ?int, amount: int}
     */
    private static function row(PayslipLine $line, string $unit): array
    {
        $explanation = $line->explanation;
        $hours = $line->quantity ?? ($explanation['hours'] ?? null);
        $rate = $line->rate_ore !== null ? $line->rate_ore->ore : (isset($explanation['hour_value_ore']) ? (int) round((float) $explanation['hour_value_ore']) : null);
        $label = (string) preg_replace('/,\s*[\d.,]+\s*timmar$/u', '', $line->description);

        return [
            'label' => $label,
            'quantity' => $hours === null ? null : self::number((string) $hours),
            'unit' => $hours === null ? null : ($unit === 'DAYS' ? 'dag' : 'tim'),
            'rate' => $rate,
            'amount' => $line->amount_ore->ore,
        ];
    }

    /**
     * The vacation balance after this payslip's period, or null while the
     * vacation rules for the date are not checked.
     *
     * @return ?array{year_start: CarbonImmutable, year_end: CarbonImmutable, earned: int, taken: int, left: int, basis_ore: int, payout_with_salary: bool}
     */
    private static function vacation(Payslip $payslip, Employee $employee, PayrollRun $run, Company $company): ?array
    {
        $employment = $payslip->employment;

        if ($employment === null) {
            return null;
        }

        try {
            return app(VacationLedger::class)->overview(
                collect([$employee]),
                collect([$employee->id => $employment]),
                $run->periodStart(),
                app(ModuleManager::class)->settings(PayrollModule::KEY, $company),
                RuleSet::for($run->payment_date),
                $run->periodEnd(),
            )[$employee->id];
        } catch (MissingPayrollRuleException) {
            return null;
        }
    }

    private static function salary(?Employment $employment): ?string
    {
        if ($employment === null) {
            return null;
        }

        return $employment->salary_form === 'HOURLY'
            ? __(':amount kr per timme', ['amount' => $employment->hourly_rate_ore?->format(withCurrency: false)])
            : __(':amount kr per månad', ['amount' => $employment->monthly_salary_ore?->format(withCurrency: false)]);
    }

    private static function number(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim(str_replace('.', ',', $value), '0'), ',') : $value;
    }

    private static function maskedAccount(Employee $employee): ?string
    {
        $account = (string) $employee->bank_account;

        return $account === '' ? null : trim($employee->bank_clearing.' ').' ••••'.substr($account, -4);
    }
}
