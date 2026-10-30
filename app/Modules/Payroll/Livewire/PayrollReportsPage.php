<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Actions\ClosePayrollYear;
use App\Modules\Payroll\Models\PayrollYearClosing;
use App\Modules\Payroll\Support\Reports\PayrollReports;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The payroll reports and the calendar year-end. Reports per person are for
 * payroll staff; an auditor sees the aggregated ones (PAYROLL_MODULE.md B.14).
 */
class PayrollReportsPage extends Component
{
    use ShowsToasts;

    public const array PERSONAL = ['journal', 'ytd'];

    public const array AGGREGATED = ['pay-codes', 'cost-centers', 'pension', 'reconciliation', 'year-end'];

    #[Url(as: 'rapport', except: 'journal')]
    public string $report = 'journal';

    #[Url(as: 'fran', except: '')]
    public string $from = '';

    #[Url(as: 'till', except: '')]
    public string $to = '';

    #[Url(as: 'ar', except: '')]
    public string $year = '';

    public bool $confirmingClose = false;

    public function mount(): void
    {
        abort_unless($this->canSeePersonal() || $this->can('payroll:archive-view'), 403);

        if (! $this->canSeePersonal() && in_array($this->report, self::PERSONAL, true)) {
            $this->report = 'pay-codes';
        }
    }

    public function closeYear(ClosePayrollYear $close): void
    {
        $closing = $close($this->user(), $this->company(), $this->selectedYear());

        $this->confirmingClose = false;
        $this->toast(__('Löneåret :year är avslutat. Ingen lön kan längre betalas ut i det.', ['year' => $closing->year]));
    }

    public function render(PayrollReports $reports): View
    {
        $personal = $this->canSeePersonal();
        $report = in_array($this->report, [...($personal ? self::PERSONAL : []), ...self::AGGREGATED], true) ? $this->report : 'pay-codes';
        $year = $this->selectedYear();
        $from = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->from) === 1 ? $this->from : CarbonImmutable::today()->startOfYear()->format('Y-m');
        $to = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->to) === 1 && $this->to >= $from ? $this->to : CarbonImmutable::today()->format('Y-m');

        return view('payroll::livewire.reports', [
            'tabs' => array_filter([
                'journal' => $personal ? __('Lönejournal') : null,
                'pay-codes' => __('Lönearter'),
                'ytd' => $personal ? __('Hittills i år') : null,
                'cost-centers' => __('Kostnadsställen'),
                'pension' => __('Pension'),
                'reconciliation' => __('Avstämning'),
                'year-end' => __('Årsskifte'),
            ]),
            'current' => $report,
            'fromMonth' => $from,
            'toMonth' => $to,
            'selectedYear' => $year,
            'rows' => match ($report) {
                'journal' => $reports->journal($from, $to),
                'pay-codes' => $reports->payCodes($from, $to),
                'ytd' => $reports->yearToDate($year),
                'cost-centers' => $reports->costCenters($from, $to),
                'pension' => $reports->pension($from, $to),
                'reconciliation' => $reports->reconciliation($year),
                default => [],
            },
            'closing' => $report === 'year-end' ? PayrollYearClosing::query()->where('year', $year)->first() : null,
            'blockers' => $report === 'year-end' ? app(ClosePayrollYear::class)->blockers($year) : [],
            'canClose' => $this->can('payroll:post'),
        ]);
    }

    private function selectedYear(): int
    {
        return preg_match('/^\d{4}$/', $this->year) === 1 ? (int) $this->year : CarbonImmutable::today()->year;
    }

    private function canSeePersonal(): bool
    {
        return $this->can('payroll:view');
    }

    private function can(string $permission): bool
    {
        return $this->user()->hasTeamPermission($this->company(), $permission);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        return $this->user()->currentCompany() ?? abort(404);
    }
}
