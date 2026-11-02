<?php

declare(strict_types=1);

namespace App\Livewire\Vat;

use App\Actions\Accounting\LockPeriod;
use App\Actions\Vat\SettleVat;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Company\VatPeriod;
use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Vat\VatBoxes;
use App\Domain\Vat\VatPeriods;
use App\Domain\Vat\VatReturn;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\VatReport;
use App\Models\VoucherRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        if ($report->version > 1) {
            $this->toast(__('Tillägget är bokfört på :reference. Lämna en rättad momsdeklaration för perioden hos Skatteverket.', [
                'reference' => $report->voucher()->firstOrFail()->reference(),
            ]));

            return;
        }

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

        // Ordered by version, so the key holds each period's latest return.
        $reports = VatReport::query()->with('voucher.series')->orderBy('version')->get()->keyBy(fn (VatReport $report): string => $report->period_start->toDateString());
        $report = $period === null ? null : $reports->get($period['start']->toDateString());
        $return = $fiscalYear === null || $period === null ? null : VatReturn::calculate($fiscalYear, $period['start'], $period['end']);
        $supplement = $report instanceof VatReport && $return instanceof VatReturn
            && $return->needsSupplement(VatReport::bookedRows(VatReport::query()->whereDate('period_start', $report->period_start)->with('voucher.rows.account')->get()));

        return view('livewire.vat.vat-returns', [
            'company' => $company,
            'registered' => $company->vat_period !== VatPeriod::None,
            'unregisteredVat' => $company->vat_period === VatPeriod::None ? self::outputVat() : 0,
            'fiscalYears' => FiscalYear::query()->orderByDesc('start_date')->get(),
            'fiscalYear' => $fiscalYear,
            'periods' => $periods,
            'selected' => $period,
            'reports' => $reports,
            'report' => $report,
            'return' => $return,
            'supplement' => $supplement,
            'canSettle' => $this->user()->hasTeamPermission($company, 'vouchers:post'),
            'canLock' => $this->user()->hasTeamPermission($company, 'periods:lock'),
        ]);
    }

    /**
     * The output VAT on the sales VAT accounts of a company that is not
     * registered: VAT charged anyway must be paid to the state (ML 16 kap.
     * 23 §; enligt granskning 2026-11-02, juristgranskning återstår
     * (AUD-FAKT-5, #51)). Credit balance as a positive amount.
     */
    private static function outputVat(): int
    {
        return -(int) VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereIn('accounts.number', array_values(InvoiceBooking::OUTPUT_VAT))
            ->sum(DB::raw('voucher_rows.debit_ore - voucher_rows.credit_ore'));
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
