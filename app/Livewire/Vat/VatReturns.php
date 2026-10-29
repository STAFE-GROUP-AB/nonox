<?php

declare(strict_types=1);

namespace App\Livewire\Vat;

use App\Actions\Accounting\LockPeriod;
use App\Actions\Vat\SettleVat;
use App\Domain\Company\VatPeriod;
use App\Domain\Vat\VatBoxes;
use App\Domain\Vat\VatPeriods;
use App\Domain\Vat\VatReturn;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\VatReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Momsdeklaration per period: the boxes with the accounts behind them, the
 * file for Skatteverket, booking the VAT and locking the period.
 */
class VatReturns extends Component
{
    use ShowsToasts;

    #[Url(except: '')]
    public string $ar = '';

    #[Url(except: '')]
    public string $period = '';

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'vat:prepare'), 403);
    }

    public function updatedAr(): void
    {
        $this->reset('period');
    }

    public function choose(string $start): void
    {
        $this->period = $start;
    }

    public function settle(SettleVat $settle): void
    {
        [$fiscalYear, $period] = $this->selection();

        if ($fiscalYear === null || $period === null) {
            return;
        }

        $report = $settle($this->user(), $fiscalYear, $period['start']);

        $this->toast(__('Momsen är bokförd på :reference. Att betala: :amount kr.', [
            'reference' => $report->voucher()->firstOrFail()->reference(),
            'amount' => number_format($report->boxes[VatBoxes::PAYABLE] ?? 0, 0, ',', ' '),
        ]));
    }

    public function lock(LockPeriod $lock): void
    {
        [, $period] = $this->selection();

        if ($period === null) {
            return;
        }

        $lock($this->user(), $this->company(), $period['end']);

        $this->toast(__('Bokföringen är låst till och med :date.', ['date' => $period['end']->toDateString()]));
    }

    public function render(): View
    {
        [$fiscalYear, $period, $periods] = [...$this->selection(), []];
        $company = $this->company();

        if ($fiscalYear !== null) {
            $periods = VatPeriods::of($fiscalYear, $company->vat_period);
        }

        $reports = VatReport::query()->with('voucher.series')->get()->keyBy(fn (VatReport $report): string => $report->period_start->toDateString());

        return view('livewire.vat.vat-returns', [
            'company' => $company,
            'registered' => $company->vat_period !== VatPeriod::None,
            'fiscalYears' => FiscalYear::query()->orderByDesc('start_date')->get(),
            'fiscalYear' => $fiscalYear,
            'periods' => $periods,
            'selected' => $period,
            'reports' => $reports,
            'report' => $period === null ? null : $reports->get($period['start']->toDateString()),
            'return' => $fiscalYear === null || $period === null ? null : VatReturn::calculate($fiscalYear, $period['start'], $period['end']),
            'canSettle' => $this->user()->hasTeamPermission($company, 'vouchers:post'),
            'canLock' => $this->user()->hasTeamPermission($company, 'periods:lock'),
        ]);
    }

    /**
     * @return array{0: ?FiscalYear, 1: ?array{start: CarbonImmutable, end: CarbonImmutable}}
     */
    private function selection(): array
    {
        $fiscalYear = FiscalYear::query()->find(is_numeric($this->ar) ? (int) $this->ar : 0)
            ?? FiscalYear::query()->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->first()
            ?? FiscalYear::query()->orderByDesc('end_date')->first();

        if (! $fiscalYear instanceof FiscalYear) {
            return [null, null];
        }

        $periods = VatPeriods::of($fiscalYear, $this->company()->vat_period);
        $today = CarbonImmutable::today();
        $chosen = collect($periods)->first(fn (array $period): bool => $period['start']->toDateString() === $this->period)
            ?? collect($periods)->last(fn (array $period): bool => $period['end']->lessThan($today))
            ?? ($periods[0] ?? null);

        return [$fiscalYear, $chosen];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
