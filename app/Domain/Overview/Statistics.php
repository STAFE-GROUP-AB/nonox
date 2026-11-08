<?php

declare(strict_types=1);

namespace App\Domain\Overview;

use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\OpeningBalance;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\VoucherRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The figures behind "Statistik och prognos" on Översikt (ADR-027), all
 * read from the posted ledger: revenue and costs per month this year and
 * last, the result per month and accumulated, the bank balance month by
 * month with a forecast (LiquidityForecast), the largest customers and cost
 * accounts, and VAT per month. A fixed number of queries, whatever the size
 * of the books: seven, one for the agreements' revenue, and the forecast's
 * explicit flows (ForecastFlows).
 *
 * Amounts are whole öre. Revenue is accounts 3000–3999 (credit minus
 * debit), costs 4000–8998 (debit minus credit; 8999 Årets resultat is the
 * closing entry, not a cost), bank and cash 1900–1999, output VAT 2610–2639
 * and input VAT 2640–2649 without the VAT settlement vouchers.
 */
final class Statistics
{
    public const int TOP = 5;

    public const int LIQUIDITY_MONTHS = 12;

    /**
     * The cost accounts of the payroll voucher's EMPLOYER lines, which are
     * paid to an insurer or through the preliminary tax and not by the
     * payroll run (PayCodeCatalog: TJANSTEPENSION 7410, SARSKILD_LONESKATT
     * 7530, AVTALSFORSAKRING 7570, credited to 2740 and 2514): BAS 74
     * Pensionskostnader, 753 Särskild löneskatt and 757 Premier för
     * arbetsmarknadsförsäkringar. They stay in the average result.
     *
     * @var list<array{0: int, 1: int}>
     */
    public const array NOT_PAID_BY_RUNS = [[7400, 7499], [7530, 7539], [7570, 7579]];

    /**
     * BAS 753 Särskild löneskatt: paid through the preliminary tax, so it
     * leaves the average with the tax costs when the preliminary tax is
     * counted on its own.
     *
     * @var array{0: int, 1: int}
     */
    public const array SPECIAL_PAYROLL_TAX = [7530, 7539];

    public function __construct(private readonly ForecastFlows $flows) {}

    /**
     * @return array<string, mixed>|null null when there is no fiscal year yet
     */
    public function for(User $user, Company $company, CarbonImmutable $today): ?array
    {
        $years = FiscalYear::query()->orderBy('start_date')->get(['id', 'start_date', 'end_date']);
        $current = $years->first(fn (FiscalYear $year): bool => $year->start_date->lessThanOrEqualTo($today) && $year->end_date->greaterThanOrEqualTo($today))
            ?? $years->last(fn (FiscalYear $year): bool => $year->start_date->lessThanOrEqualTo($today));

        if (! $current instanceof FiscalYear) {
            return null;
        }

        $previous = $years->last(fn (FiscalYear $year): bool => $year->end_date->lessThan($current->start_date));
        $from = $previous instanceof FiscalYear ? $previous->start_date : $current->start_date;
        $monthly = $this->monthly($from, $current->end_date, $today);

        $months = [];
        for ($month = $current->start_date->startOfMonth(); $month->lessThanOrEqualTo($current->end_date); $month = $month->addMonthNoOverflow()) {
            $months[] = $month;
        }

        $series = fn (string $field, int $shift): array => array_map(function (CarbonImmutable $month) use ($monthly, $field, $shift, $today): ?int {
            $key = $month->subMonthsNoOverflow($shift)->format('Y-m');

            if ($shift === 0 && $month->greaterThan($today)) {
                return null;
            }

            return (int) ($monthly[$key][$field] ?? 0);
        }, $months);

        $revenue = $series('revenue', 0);
        $costs = $series('costs', 0);
        $result = array_map(fn (?int $r, ?int $c): ?int => $r === null ? null : $r - (int) $c, $revenue, $costs);
        $accumulated = [];
        $sum = 0;
        foreach ($result as $value) {
            $sum += (int) $value;
            $accumulated[] = $value === null ? null : $sum;
        }

        $shift = $previous instanceof FiscalYear ? (int) round($previous->start_date->diffInMonths($current->start_date)) : 0;
        $hasPrevious = $previous instanceof FiscalYear;

        return [
            'year' => $current->label(),
            'previous_year' => $hasPrevious ? $previous->label() : null,
            'months' => array_map(fn (CarbonImmutable $month): string => $month->translatedFormat('M'), $months),
            'revenue' => ['current' => $revenue, 'previous' => $hasPrevious ? $series('revenue', $shift) : null],
            'costs' => ['current' => $costs, 'previous' => $hasPrevious ? $series('costs', $shift) : null],
            'result' => ['monthly' => $result, 'accumulated' => $accumulated],
            'vat' => [
                'output' => $series('vat_out', 0),
                'input' => $series('vat_in', 0),
            ],
            'liquidity' => $this->liquidity($user, $company, $current, $from, $monthly, $today),
            'top_customers' => $user->hasTeamPermission($company, 'invoices:view') ? $this->topCustomers($current, $today) : null,
            'top_accounts' => $this->topAccounts($current, $today),
        ];
    }

    /**
     * Revenue, costs, bank and cash, and VAT per month in one query.
     *
     * @return array<string, array{revenue: int, costs: int, cash: int, vat_out: int, vat_in: int, payroll_costs: int, tax_costs: int}>
     */
    private function monthly(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today): array
    {
        $vat = VoucherSource::Vat->value;

        return VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereBetween('vouchers.voucher_date', [$from->toDateString(), $to->toDateString()])
            ->toBase()
            ->selectRaw("to_char(vouchers.voucher_date, 'YYYY-MM') AS month")
            ->selectRaw('COALESCE(SUM(voucher_rows.credit_ore - voucher_rows.debit_ore) FILTER (WHERE accounts.number BETWEEN 3000 AND 3999), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) FILTER (WHERE accounts.number BETWEEN 4000 AND 8998), 0) AS costs')
            ->selectRaw('COALESCE(SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) FILTER (WHERE accounts.number BETWEEN 1900 AND 1999 AND vouchers.voucher_date <= ?), 0) AS cash', [$today->toDateString()])
            ->selectRaw('COALESCE(SUM(voucher_rows.credit_ore - voucher_rows.debit_ore) FILTER (WHERE accounts.number BETWEEN 2610 AND 2639 AND vouchers.source <> ?), 0) AS vat_out', [$vat])
            ->selectRaw('COALESCE(SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) FILTER (WHERE accounts.number BETWEEN 2640 AND 2649 AND vouchers.source <> ?), 0) AS vat_in', [$vat])
            // What the forecast counts on its own instead of through the average result (ForecastFlows): the
            // payroll vouchers' costs the runs pay, without the accounts in NOT_PAID_BY_RUNS.
            ->selectRaw('COALESCE(SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) FILTER (WHERE accounts.number BETWEEN 4000 AND 8998 AND vouchers.source = ? AND NOT (accounts.number BETWEEN ? AND ? OR accounts.number BETWEEN ? AND ? OR accounts.number BETWEEN ? AND ?)), 0) AS payroll_costs', [VoucherSource::Payroll->value, ...array_merge(...self::NOT_PAID_BY_RUNS)])
            // The tax costs and särskild löneskatt, which the preliminary tax pays when the company has entered it.
            ->selectRaw('COALESCE(SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) FILTER (WHERE accounts.number BETWEEN 8900 AND 8998 OR accounts.number BETWEEN ? AND ?), 0) AS tax_costs', self::SPECIAL_PAYROLL_TAX)
            ->groupByRaw('1')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->month => [
                'revenue' => (int) $row->revenue,
                'costs' => (int) $row->costs,
                'cash' => (int) $row->cash,
                'vat_out' => (int) $row->vat_out,
                'vat_in' => (int) $row->vat_in,
                'tax_costs' => (int) $row->tax_costs,
                'payroll_costs' => (int) $row->payroll_costs,
            ]])
            ->all();
    }

    /**
     * Bank and cash at the end of each of the last months, today, and the
     * forecast for the end of this month and the next two. Earlier month
     * ends are counted back from today's balance with the months' movements.
     *
     * The average result leaves out what the forecast counts on its own, so
     * nothing is counted twice (ADR-027, addendum 2026-11-08): what the booked
     * payroll runs paid (net pay, tax, employer contributions) when payroll is
     * counted from the runs, while pension, särskild löneskatt and other costs
     * of the payroll vouchers the runs do not pay stay in the average; the tax
     * costs (8900–8998) when the preliminary tax is counted; and the revenue
     * of the invoices made from agreements, less their credit notes, which
     * are expected on their own dates.
     *
     * @param  array<string, array{revenue: int, costs: int, cash: int, vat_out: int, vat_in: int, payroll_costs: int, tax_costs: int}>  $monthly
     * @return array{actual: list<array{label: string, date: string, balance: int}>, forecast: list<array{label: string, month: string, inflow: int, outflow: int, recurring: int, payroll: int, vat: int, tax_account: int, trend: int, balance: int}>, today: int, average_result: int, included: array{recurring: bool, payroll: bool, vat: bool, preliminary_tax: bool}}
     */
    private function liquidity(User $user, Company $company, FiscalYear $current, CarbonImmutable $from, array $monthly, CarbonImmutable $today): array
    {
        $opening = (int) OpeningBalance::query()
            ->join('accounts', 'accounts.id', '=', 'opening_balances.account_id')
            ->where('opening_balances.fiscal_year_id', $current->id)
            ->whereBetween('accounts.number', [1900, 1999])
            ->sum('opening_balances.amount_ore');

        $balance = $opening;
        for ($month = $current->start_date->startOfMonth(); $month->lessThanOrEqualTo($today); $month = $month->addMonthNoOverflow()) {
            $balance += $monthly[$month->format('Y-m')]['cash'] ?? 0;
        }

        $actual = [['label' => __('I dag'), 'date' => $today->toDateString(), 'balance' => $balance]];
        $running = $balance;
        $earliest = $from->startOfMonth();

        for ($i = 1; $i <= self::LIQUIDITY_MONTHS; $i++) {
            $month = $today->startOfMonth()->subMonthsNoOverflow($i);

            if ($month->lessThan($earliest)) {
                break;
            }

            $running -= $monthly[$month->addMonthNoOverflow()->format('Y-m')]['cash'] ?? 0;
            array_unshift($actual, ['label' => $month->translatedFormat('M Y'), 'date' => $month->endOfMonth()->toDateString(), 'balance' => $running]);
        }

        $explicit = $this->flows->for($user, $company, $today);
        $included = $explicit['included'];
        $agreementRevenue = $this->agreementRevenue($today->startOfMonth()->subMonthsNoOverflow(3), $today->startOfMonth()->subDay());

        $results = [];
        for ($i = 1; $i <= 3; $i++) {
            $key = $today->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m');

            if ($today->startOfMonth()->subMonthsNoOverflow($i)->greaterThanOrEqualTo($earliest)) {
                $results[] = ($monthly[$key]['revenue'] ?? 0) - ($monthly[$key]['costs'] ?? 0)
                    + ($included['payroll'] ? ($monthly[$key]['payroll_costs'] ?? 0) : 0)
                    + ($included['preliminary_tax'] ? ($monthly[$key]['tax_costs'] ?? 0) : 0)
                    - ($agreementRevenue[$key] ?? 0);
            }
        }

        $average = LiquidityForecast::averageResult($results);
        $forecast = LiquidityForecast::project($balance, $today, $this->openByDueDate(Invoice::class), $this->openByDueDate(SupplierInvoice::class), $average, LiquidityForecast::MONTHS, $explicit['flows']);

        return [
            'actual' => $actual,
            'forecast' => array_map(fn (array $point): array => ['label' => CarbonImmutable::parse("{$point['month']}-01")->translatedFormat('M Y'), ...$point], $forecast),
            'today' => $balance,
            'average_result' => $average,
            'included' => $included,
        ];
    }

    /**
     * The revenue (excluding VAT) of the invoices made from agreements, per
     * month of the invoice date, less the credit notes of those invoices
     * (negative, in the month of the credit note, as the ledger has them): the
     * forecast expects the agreements' invoices on their own dates, so the
     * average result leaves them out, and a credited one nets to nothing.
     *
     * @return array<string, int>
     */
    private function agreementRevenue(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Invoice::query()
            ->where('invoices.status', '<>', InvoiceStatus::Draft->value)
            ->leftJoin('invoices AS originals', 'originals.id', '=', 'invoices.credits_invoice_id')
            ->where(fn ($query) => $query
                ->where(fn ($invoice) => $invoice->where('invoices.kind', Invoice::INVOICE)->whereNotNull('invoices.recurring_invoice_id'))
                ->orWhere(fn ($credit) => $credit->where('invoices.kind', Invoice::CREDIT)->whereNotNull('originals.recurring_invoice_id')))
            ->whereBetween('invoices.invoice_date', [$from->toDateString(), $to->toDateString()])
            ->toBase()
            ->selectRaw("to_char(invoices.invoice_date, 'YYYY-MM') AS month, SUM(invoices.net_ore) AS amount")
            ->groupByRaw('1')
            ->pluck('amount', 'month')
            ->map(fn (mixed $amount): int => (int) $amount)
            ->all();
    }

    /**
     * What is left to pay on open invoices, summed per due date.
     *
     * @param  class-string<Invoice|SupplierInvoice>  $model
     * @return list<array{due: string, amount: int}>
     */
    private function openByDueDate(string $model): array
    {
        $query = $model === Invoice::class
            ? Invoice::query()->where('status', InvoiceStatus::Issued->value)->where('kind', Invoice::INVOICE)
            : SupplierInvoice::query()->whereIn('status', [SupplierInvoiceStatus::Registered->value, SupplierInvoiceStatus::Approved->value]);

        return array_values($query->toBase()
            ->whereNotNull('due_date')
            ->groupBy('due_date')
            ->select('due_date', DB::raw('SUM(total_ore - paid_ore) AS amount'))
            ->get()
            ->map(fn (object $row): array => ['due' => substr((string) $row->due_date, 0, 10), 'amount' => (int) $row->amount])
            ->all());
    }

    /**
     * The customers invoiced the most this fiscal year, excluding VAT;
     * credit notes count against them.
     *
     * @return list<array{name: string, amount: int}>
     */
    private function topCustomers(FiscalYear $year, CarbonImmutable $today): array
    {
        return array_values(Invoice::query()
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->where('invoices.status', '<>', InvoiceStatus::Draft->value)
            ->whereBetween('invoices.invoice_date', [$year->start_date->toDateString(), ($year->end_date->lessThan($today) ? $year->end_date : $today)->toDateString()])
            ->groupBy('customers.id', 'customers.name')
            ->toBase()
            ->select('customers.name')
            ->selectRaw('SUM(CASE WHEN invoices.kind = ? THEN -ABS(invoices.net_ore) ELSE invoices.net_ore END) AS amount', [Invoice::CREDIT])
            ->orderByDesc('amount')
            ->limit(self::TOP)
            ->get()
            ->map(fn (object $row): array => ['name' => (string) $row->name, 'amount' => (int) $row->amount])
            ->all());
    }

    /**
     * The operating cost accounts (4000–7999) with the most cost this
     * fiscal year.
     *
     * @return list<array{number: int, name: string, amount: int}>
     */
    private function topAccounts(FiscalYear $year, CarbonImmutable $today): array
    {
        return array_values(VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->where('vouchers.fiscal_year_id', $year->id)
            ->whereDate('vouchers.voucher_date', '<=', $today->toDateString())
            ->whereBetween('accounts.number', [4000, 7999])
            ->groupBy('accounts.number', 'accounts.name')
            ->toBase()
            ->select('accounts.number', 'accounts.name')
            ->selectRaw('SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) AS amount')
            ->havingRaw('SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) > 0')
            ->orderByDesc('amount')
            ->limit(self::TOP)
            ->get()
            ->map(fn (object $row): array => ['number' => (int) $row->number, 'name' => (string) $row->name, 'amount' => (int) $row->amount])
            ->all());
    }
}
