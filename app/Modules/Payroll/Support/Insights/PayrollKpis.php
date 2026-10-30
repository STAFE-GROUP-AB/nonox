<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Insights;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\VacationLiabilitySnapshot;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * The payroll figures an employer wants in view on every payroll page: the
 * month's salary cost, the next payout, tax and contributions still to pay,
 * employer declarations left, the vacation liability and the staff. Each
 * figure is one query, and only for users who may see payroll.
 */
final class PayrollKpis
{
    private const array PAID = [PayrollRun::APPROVED, PayrollRun::PAID, PayrollRun::POSTED];

    /**
     * @return list<array{label: string, value: Money|string, hint: ?string, warn: bool, link: ?string}>
     */
    public function for(User $user, Company $company, ?CarbonImmutable $today = null): array
    {
        if (! $user->hasTeamPermission($company, 'payroll:view')) {
            return [];
        }

        $today ??= CarbonImmutable::today();

        return CurrentCompany::run($company, fn (): array => [
            $this->cost($today),
            $this->nextPayout($today),
            $this->taxAccount($today),
            $this->declarations(),
            $this->vacationLiability(),
            $this->staff($today),
        ]);
    }

    /** @return array{label: string, value: Money, hint: ?string, warn: bool, link: ?string} */
    private function cost(CarbonImmutable $today): array
    {
        $months = [$today->format('Y-m'), $today->subMonthNoOverflow()->format('Y-m')];
        $sums = Payslip::query()->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->whereIn('payroll_runs.status', self::PAID)
            ->whereRaw("to_char(payroll_runs.payment_date, 'YYYY-MM') in (?, ?)", $months)
            ->selectRaw("to_char(payroll_runs.payment_date, 'YYYY-MM') as month, sum(payslips.gross_ore) as gross, sum(payslips.employer_contribution_ore) as contributions")
            ->groupByRaw('1')->get()->keyBy('month');
        $total = fn (string $month): int => (int) $sums->get($month)?->getAttribute('gross') + (int) $sums->get($month)?->getAttribute('contributions');

        return [
            'label' => __('Lönekostnad :month', ['month' => $today->translatedFormat('F')]),
            'value' => Money::ofOre($total($months[0])),
            'hint' => __('Bruttolön och arbetsgivaravgifter. Förra månaden: :amount', ['amount' => Money::ofOre($total($months[1]))->format()]),
            'warn' => false,
            'link' => route('payroll.reports'),
        ];
    }

    /** @return array{label: string, value: Money|string, hint: ?string, warn: bool, link: ?string} */
    private function nextPayout(CarbonImmutable $today): array
    {
        $run = PayrollRun::query()->whereIn('status', [PayrollRun::DRAFT, PayrollRun::CALCULATED, PayrollRun::APPROVED])
            ->whereDate('payment_date', '>=', $today->toDateString())
            ->withSum('payslips', 'net_ore')
            ->orderBy('payment_date')->first();

        if (! $run instanceof PayrollRun) {
            return ['label' => __('Nästa löneutbetalning'), 'value' => '–', 'hint' => __('Ingen lönekörning är planerad.'), 'warn' => false, 'link' => route('payroll.runs.index')];
        }

        return [
            'label' => __('Nästa löneutbetalning'),
            'value' => Money::ofOre((int) $run->getAttribute('payslips_sum_net_ore')),
            'hint' => __(':date · :status', ['date' => $run->payment_date->toDateString(), 'status' => $run->statusLabel()]),
            'warn' => $run->status !== PayrollRun::APPROVED && $run->payment_date->lessThanOrEqualTo($today->addDays(3)),
            'link' => route('payroll.runs.show', $run),
        ];
    }

    /** @return array{label: string, value: Money, hint: ?string, warn: bool, link: ?string} */
    private function taxAccount(CarbonImmutable $today): array
    {
        $row = Payslip::query()->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->whereIn('payroll_runs.status', self::PAID)->whereNull('payroll_runs.tax_voucher_id')
            ->selectRaw('sum(payslips.tax_ore) as tax, sum(payslips.employer_contribution_ore) as contributions, min(payroll_runs.payment_date) as first')
            ->first();
        $amount = (int) $row?->getAttribute('tax') + (int) $row?->getAttribute('contributions');
        $first = $row?->getAttribute('first');
        // Skatteverket draws tax and contributions around the 12th of the month after payment.
        $due = $first === null ? null : CarbonImmutable::parse((string) $first)->addMonthNoOverflow()->day(12);

        return [
            'label' => __('Skatt och avgifter att betala'),
            'value' => Money::ofOre($amount),
            'hint' => $due === null ? __('Allt är bokfört mot skattekontot.') : __('Avdragen skatt och arbetsgivaravgifter, omkring :date.', ['date' => $due->toDateString()]),
            'warn' => $due !== null && $due->lessThan($today),
            'link' => route('payroll.runs.index'),
        ];
    }

    /** @return array{label: string, value: string, hint: ?string, warn: bool, link: ?string} */
    private function declarations(): array
    {
        $paid = PayrollRun::query()->whereIn('status', self::PAID)->selectRaw("distinct to_char(payment_date, 'YYYY-MM') as month")->pluck('month')->all();
        $declared = AgiSubmission::query()->whereIn('status', AgiSubmission::REPORTED)->distinct()->pluck('period')->all();
        $missing = array_values(array_diff($paid, $declared));
        sort($missing);

        return [
            'label' => __('Arbetsgivardeklarationer att lämna'),
            'value' => (string) count($missing),
            'hint' => $missing === [] ? __('Alla utbetalda månader är deklarerade.') : __('Äldsta: :month', ['month' => $missing[0]]),
            'warn' => $missing !== [],
            'link' => route('payroll.agi'),
        ];
    }

    /** @return array{label: string, value: Money|string, hint: ?string, warn: bool, link: ?string} */
    private function vacationLiability(): array
    {
        $snapshot = VacationLiabilitySnapshot::query()->latest('as_of')->latest('id')->first();

        return [
            'label' => __('Semesterlöneskuld'),
            'value' => $snapshot === null ? '–' : Money::ofOre($snapshot->liability_ore + $snapshot->contributions_ore),
            'hint' => $snapshot === null ? __('Räknas fram vid nästa lönekörning.') : __('Med sociala avgifter, per :date.', ['date' => $snapshot->as_of->toDateString()]),
            'warn' => false,
            'link' => route('payroll.vacationLiability'),
        ];
    }

    /** @return array{label: string, value: string, hint: ?string, warn: bool, link: ?string} */
    private function staff(CarbonImmutable $today): array
    {
        $counts = Employment::query()
            ->whereDate('start_date', '<=', $today->toDateString())
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today->toDateString()))
            ->selectRaw("count(distinct employee_id) as employees, count(distinct employee_id) filter (where salary_form = 'HOURLY') as hourly")
            ->first();

        return [
            'label' => __('Anställda'),
            'value' => (string) (int) $counts?->getAttribute('employees'),
            'hint' => __('Varav :count med timlön.', ['count' => (int) $counts?->getAttribute('hourly')]),
            'warn' => false,
            'link' => route('payroll.employees'),
        ];
    }
}
