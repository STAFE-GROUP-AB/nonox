<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollYearClosing;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a payroll calendar year (PAYROLL_MODULE.md B.14) once every run
 * paid in it is posted and every month with pay is declared to
 * Skatteverket. After that no run can be paid in the year, so the
 * accumulators and the yearly totals stay as declared. A declaration for the
 * year can still be corrected. The vacation year is closed on its own page.
 */
class ClosePayrollYear
{
    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * What stops the year from being closed, in plain Swedish.
     *
     * @return list<string>
     */
    public function blockers(int $year): array
    {
        $blockers = [];

        if ($year >= CarbonImmutable::today()->year) {
            $blockers[] = __('Året är inte slut ännu.');
        }

        $runs = PayrollRun::query()->whereBetween('payment_date', ["{$year}-01-01", "{$year}-12-31"])->get();

        foreach ($runs->where('status', '!==', PayrollRun::POSTED) as $run) {
            $blockers[] = __('Lönekörningen :title är inte bokförd.', ['title' => $run->title()]);
        }

        $declared = AgiSubmission::query()->where('period', 'like', "{$year}-%")->whereIn('status', [...AgiSubmission::REPORTED, AgiSubmission::CORRECTED])->pluck('period')->unique();

        foreach ($runs->map(fn (PayrollRun $run): string => $run->payment_date->format('Y-m'))->unique()->sort() as $period) {
            if (! $declared->contains($period)) {
                $blockers[] = __('Arbetsgivardeklarationen för :period är inte inlämnad.', ['period' => $period]);
            }
        }

        return $blockers;
    }

    public function __invoke(User $user, Company $company, int $year): PayrollYearClosing
    {
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'payroll:post')) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, fn (): PayrollYearClosing => DB::transaction(function () use ($user, $company, $year): PayrollYearClosing {
            if (PayrollYearClosing::isClosed($year)) {
                throw ValidationException::withMessages(['year' => [__('Löneåret :year är redan avslutat.', ['year' => $year])]]);
            }

            $blockers = $this->blockers($year);

            if ($blockers !== []) {
                throw ValidationException::withMessages(['year' => $blockers]);
            }

            $payslips = Payslip::query()->whereIn('payroll_run_id', PayrollRun::query()->whereBetween('payment_date', ["{$year}-01-01", "{$year}-12-31"])->select('id'))->get();
            $totals = [
                'payslips' => $payslips->count(),
                'gross_ore' => (int) $payslips->sum(fn (Payslip $payslip): int => $payslip->gross_ore->ore),
                'tax_ore' => (int) $payslips->sum(fn (Payslip $payslip): int => $payslip->tax_ore->ore),
                'contributions_ore' => (int) $payslips->sum(fn (Payslip $payslip): int => $payslip->employer_contribution_ore->ore),
                'net_ore' => (int) $payslips->sum(fn (Payslip $payslip): int => $payslip->net_ore->ore),
            ];

            $closing = PayrollYearClosing::create(['year' => $year, 'totals' => $totals, 'closed_by' => $user->id]);
            Audit::record('payroll.year_closed', $closing, ['year' => $year, ...$totals], $company, $user);

            return $closing;
        }));
    }
}
