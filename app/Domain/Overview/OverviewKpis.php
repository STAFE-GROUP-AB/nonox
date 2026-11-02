<?php

declare(strict_types=1);

namespace App\Domain\Overview;

use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Company\VatPeriod;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Overview\Todos\BankTodos;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Domain\Vat\EuTrade;
use App\Domain\Vat\VatDeadlines;
use App\Domain\Vat\VatPeriods;
use App\Domain\Vat\VatReturn;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\OpeningBalance;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\VatReport;
use App\Models\VoucherRow;
use App\Policies\SupplierInvoicePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The four key figures at the top of Översikt: the company account, what
 * customers owe, what is to be paid to suppliers and the next VAT return.
 * Each only for a role that may read it, each a query or two.
 */
final class OverviewKpis
{
    /** A VAT return due within this many days is highlighted. */
    public const int VAT_SOON_DAYS = 14;

    /**
     * @return list<array{key: string, label: string, value: int, hint: ?string, tone: string, link: ?string}>
     */
    public function for(User $user, Company $company, CarbonImmutable $today): array
    {
        $year = FiscalYear::query()
            ->whereDate('start_date', '<=', $today->toDateString())
            ->whereDate('end_date', '>=', $today->toDateString())
            ->first();

        return array_values(array_filter([
            $user->hasTeamPermission($company, 'reports:view') ? $this->bank($year, $today) : null,
            $user->hasTeamPermission($company, 'invoices:view') ? $this->receivables($today) : null,
            SupplierInvoicePolicy::canSeePayables($user, $company) ? $this->payables($today) : null,
            $user->hasTeamPermission($company, 'vat:prepare') ? $this->vat($company, $today) : null,
        ]));
    }

    /**
     * The balance of the company account today by the books (opening
     * balance plus what is posted up to today) and how far it is reconciled.
     *
     * @return array{key: string, label: string, value: int, hint: ?string, tone: string, link: ?string}|null
     */
    private function bank(?FiscalYear $year, CarbonImmutable $today): ?array
    {
        $account = Account::query()->where('number', BankTodos::ACCOUNT)->first(['id', 'number', 'name']);

        if (! $account instanceof Account || ! $year instanceof FiscalYear) {
            return null;
        }

        $opening = (int) OpeningBalance::query()
            ->where('fiscal_year_id', $year->id)
            ->where('account_id', $account->id)
            ->sum('amount_ore');

        $movements = (int) VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->where('vouchers.fiscal_year_id', $year->id)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereDate('vouchers.voucher_date', '<=', $today->toDateString())
            ->where('voucher_rows.account_id', $account->id)
            ->sum(DB::raw('voucher_rows.debit_ore - voucher_rows.credit_ore'));

        $reconciled = BankReconciliation::query()->where('account_id', $account->id)->max('statement_date');

        return [
            'key' => 'bank',
            'label' => __('Företagskonto :number', ['number' => $account->number]),
            'value' => $opening + $movements,
            'hint' => $reconciled === null
                ? __('Inte avstämt ännu')
                : __('Avstämt t.o.m. :date', ['date' => CarbonImmutable::parse((string) $reconciled)->translatedFormat('j M')]),
            'tone' => 'dark',
            'link' => route('accounting.bankReconciliation'),
        ];
    }

    /**
     * @return array{key: string, label: string, value: int, hint: ?string, tone: string, link: ?string}
     */
    private function receivables(CarbonImmutable $today): array
    {
        $row = Invoice::query()
            ->toBase()
            ->where('status', InvoiceStatus::Issued->value)
            ->where('kind', Invoice::INVOICE)
            ->selectRaw('COALESCE(SUM(total_ore - paid_ore), 0) AS unpaid, COUNT(*) AS invoices')
            ->selectRaw('COUNT(*) FILTER (WHERE due_date < ?) AS overdue', [$today->toDateString()])
            ->first();

        $count = (int) ($row->invoices ?? 0);
        $overdue = (int) ($row->overdue ?? 0);

        return [
            'key' => 'receivables',
            'label' => __('Obetalda kundfakturor'),
            'value' => (int) ($row->unpaid ?? 0),
            'hint' => match (true) {
                $count === 0 => __('Inga obetalda fakturor.'),
                $overdue === 0 => __($count === 1 ? '1 faktura, ingen förfallen' : ':count fakturor, ingen förfallen', ['count' => $count]),
                default => __(':invoices, :overdue förfallen', [
                    'invoices' => __($count === 1 ? '1 faktura' : ':count fakturor', ['count' => $count]),
                    'overdue' => $overdue,
                ]),
            },
            'tone' => 'plain',
            'link' => route('invoicing.invoices.index', ['visa' => $overdue > 0 ? 'forfallna' : 'obetalda']),
        ];
    }

    /**
     * @return array{key: string, label: string, value: int, hint: ?string, tone: string, link: ?string}
     */
    private function payables(CarbonImmutable $today): array
    {
        $row = SupplierInvoice::query()
            ->toBase()
            ->whereIn('status', [SupplierInvoiceStatus::Registered->value, SupplierInvoiceStatus::Approved->value])
            ->selectRaw('COALESCE(SUM(total_ore - paid_ore), 0) AS unpaid, COUNT(*) AS invoices')
            ->selectRaw('COUNT(*) FILTER (WHERE due_date < ?) AS overdue', [$today->toDateString()])
            ->first();

        $count = (int) ($row->invoices ?? 0);
        $overdue = (int) ($row->overdue ?? 0);

        return [
            'key' => 'payables',
            'label' => __('Att betala'),
            'value' => (int) ($row->unpaid ?? 0),
            'hint' => match (true) {
                $count === 0 => __('Inga obetalda leverantörsfakturor.'),
                $overdue === 0 => __($count === 1 ? '1 leverantörsfaktura' : ':count leverantörsfakturor', ['count' => $count]),
                default => __(':invoices, :overdue förfallen', [
                    'invoices' => __($count === 1 ? '1 leverantörsfaktura' : ':count leverantörsfakturor', ['count' => $count]),
                    'overdue' => $overdue,
                ]),
            },
            'tone' => $overdue > 0 ? 'warn' : 'plain',
            'link' => route('payables.invoices.index', ['visa' => $overdue > 0 ? 'forfallna' : 'att-betala']),
        ];
    }

    /**
     * The VAT return to work on: the oldest period that has ended without a
     * return, or the running period when all are filed. Its amount is the
     * return's box 49, as on Moms.
     *
     * @return array{key: string, label: string, value: int, hint: ?string, tone: string, link: ?string}|null
     */
    private function vat(Company $company, CarbonImmutable $today): ?array
    {
        if (! $company->vat_registered || $company->vat_period === VatPeriod::None) {
            return null;
        }

        $reported = VatReport::query()->pluck('period_start')
            ->map(fn (mixed $date): string => CarbonImmutable::parse((string) $date)->toDateString())
            ->all();
        $years = FiscalYear::query()->where('status', FiscalYearStatus::Open->value)->orderBy('start_date')->get();
        $chosen = null;

        foreach ($years as $year) {
            foreach (VatPeriods::of($year, $company->vat_period) as $period) {
                $ended = $period['end']->lessThan($today);
                $running = $period['start']->lessThanOrEqualTo($today) && ! $ended;

                if ($ended && ! in_array($period['start']->toDateString(), $reported, true)) {
                    $chosen = [$year, $period];

                    break 2;
                }

                if ($running && $chosen === null) {
                    $chosen = [$year, $period];
                }
            }
        }

        if ($chosen === null) {
            return null;
        }

        [$year, $period] = $chosen;
        $euTrade = $company->vat_period === VatPeriod::Yearly
            && in_array(EuTrade::key($company->id, $period['start']->toDateString()), EuTrade::years([$company->id]), true);
        $due = VatDeadlines::for($company->vat_period, $period['end'], $company->turnover_over_40_msek, $company->legal_form, $euTrade);
        $ended = $period['end']->lessThan($today);
        $payable = VatReturn::calculate($year, $period['start'], $period['end'])->payable() * 100;

        return [
            'key' => 'vat',
            'label' => __('Moms :period', ['period' => $period['start']->isSameMonth($period['end']) ? $period['start']->translatedFormat('F Y') : VatPeriods::label($period['start'], $period['end'])]),
            'value' => $payable,
            'hint' => $this->vatHint($due, $ended, $payable, $today),
            'tone' => $ended && $due !== null && $due->lessThanOrEqualTo($today->addDays(self::VAT_SOON_DAYS)) ? 'warn' : 'plain',
            'link' => route('vat.index'),
        ];
    }

    private function vatHint(?CarbonImmutable $due, bool $ended, int $payable, CarbonImmutable $today): ?string
    {
        if ($due === null && $payable >= 0) {
            return null;
        }

        if ($due === null) {
            return __('Att få tillbaka');
        }

        if ($due->lessThan($today)) {
            return __('Skulle ha lämnats :date', ['date' => $due->translatedFormat('j F')]);
        }

        if ($ended) {
            return __('Lämnas senast :date', ['date' => $due->translatedFormat('j F')]);
        }

        return __('Pågår, lämnas senast :date', ['date' => $due->translatedFormat('j F')]);
    }
}
