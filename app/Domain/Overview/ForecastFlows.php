<?php

declare(strict_types=1);

namespace App\Domain\Overview;

use App\Domain\Accounting\VoucherStatus;
use App\Domain\Deadlines\AgiDates;
use App\Domain\Deadlines\CompanyDeadlines;
use App\Domain\Deadlines\Deadline;
use App\Domain\Deadlines\DeadlineCompletion;
use App\Domain\Deadlines\DeadlineType;
use App\Domain\Guide\MoneyNotYours;
use App\Domain\Guide\MoneyNotYoursFigures;
use App\Domain\Invoicing\AgreementStatus;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Models\VoucherRow;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\PayrollModule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The explicit flows of the liquidity forecast besides the open invoices
 * (ADR-027, addendum 2026-11-08), each dated by rules already in NONOX:
 *
 * - Agreements (avtalsfakturering): every coming invoice date of an active
 *   agreement plus the customer's payment terms, and the drafts an agreement
 *   has made but nobody has issued yet, on their due dates.
 * - VAT: each period whose date (VatDeadlines, through the deadline engine)
 *   falls in the forecast, as the VAT report computes it (MoneyNotYours::
 *   vatByDeadline: a running period counts what is booked so far, a reported
 *   one is left out), and what is reported but not paid (2650) on the date
 *   of the reported period, or at once.
 * - Preliminary tax: only the monthly amount the company has entered from
 *   its decision, on the payment dates of the deadline engine after today,
 *   never a month already debited on 1630 (as Momsbufferten counts it).
 * - The tax account: the balance of 1630 today.
 * - Payroll, when the payroll module is on and a run of the last three
 *   months exists: net pay of runs calculated, approved or paid but not yet
 *   booked on their payment dates, and their withheld tax and employer
 *   contributions on the employer declaration's payment day (AgiDates); the
 *   tax and contributions already booked (2710, 2730, 2731) on the payment
 *   day of the latest booked run's month; and for a month without a run, an
 *   estimate equal to the latest run. Unbooked runs only for someone who may
 *   see payroll (payroll:view); the others get the estimate.
 *
 * Fixed number of queries whatever the history: the deadline engine,
 * Momsbufferten's balances, three per VAT period in the forecast, and one
 * each for preliminary tax, agreements, their drafts and the payroll runs.
 *
 * @phpstan-import-type Flows from LiquidityForecast
 * @phpstan-import-type Flow from LiquidityForecast
 */
final class ForecastFlows
{
    /** How far back a run counts as the company's payroll today. */
    public const int PAYROLL_MONTHS = 3;

    public function __construct(
        private readonly CompanyDeadlines $deadlines,
        private readonly MoneyNotYours $money,
        private readonly ModuleManager $modules,
        private readonly ModuleRegistry $registry,
    ) {}

    /**
     * @return array{flows: Flows, included: array{recurring: bool, payroll: bool, vat: bool, preliminary_tax: bool}}
     */
    public function for(User $user, Company $company, CarbonImmutable $today, int $months = LiquidityForecast::MONTHS): array
    {
        $today = $today->startOfDay();
        $until = $today->startOfMonth()->addMonthsNoOverflow($months - 1)->endOfMonth()->startOfDay();
        $deadlines = array_values(array_filter(
            $this->deadlines->upcoming($company, $today),
            fn (Deadline $deadline): bool => ! $deadline->date->greaterThan($until),
        ));
        $figures = $this->money->for($company, $today, $deadlines);

        $vat = $this->vat($company, $deadlines, $figures, $today);
        $preliminary = $this->preliminaryTax($company, $deadlines, $today);
        $recurring = $this->recurring($until);
        $payroll = $this->payroll($user, $company, $figures, $today, $months);

        return [
            'flows' => [
                'recurring' => $recurring,
                'payroll_net' => $payroll['net'] ?? [],
                'payroll_tax' => $payroll['tax'] ?? [],
                'vat' => $vat,
                'preliminary_tax' => $preliminary ?? [],
                'tax_account' => (int) ($figures?->taxAccount['amount'] ?? 0),
            ],
            'included' => [
                'recurring' => $recurring !== [],
                'payroll' => $payroll !== null,
                'vat' => $company->isVatRegistered() || $vat !== [],
                'preliminary_tax' => $preliminary !== null,
            ],
        ];
    }

    /**
     * @param  list<Deadline>  $deadlines  the company's dates from today to the end of the forecast
     * @return list<Flow> positive to pay, negative to get back
     */
    private function vat(Company $company, array $deadlines, ?MoneyNotYoursFigures $figures, CarbonImmutable $today): array
    {
        $periods = array_values(array_filter($deadlines, fn (Deadline $deadline): bool => $deadline->type === DeadlineType::Vat && $deadline->periodStart !== null));
        $done = $periods === [] ? [] : DeadlineCompletion::done($company, $periods);
        $flows = [];

        foreach ($this->money->vatByDeadline($company, $periods, $today, $done) as $item) {
            if ($item['amount'] !== 0) {
                $flows[] = ['due' => $item['deadline']->date->toDateString(), 'amount' => $item['amount']];
            }
        }

        // Reported but not paid: due on the date of the reported period, or at once when that date has passed.
        $reported = (int) ($figures?->vatReported['amount'] ?? 0);

        if ($reported !== 0) {
            $period = collect($periods)->first(fn (Deadline $deadline): bool => in_array($deadline->key, $done, true));
            $flows[] = ['due' => ($period === null ? $today : $period->date)->toDateString(), 'amount' => $reported];
        }

        return $flows;
    }

    /**
     * Null when the company has no amount of debited preliminary tax entered.
     *
     * TODO(legal-verify): the day Skatteverket debits the preliminary tax on
     * the tax account (as MoneyNotYours::preliminaryTax).
     *
     * @param  list<Deadline>  $deadlines
     * @return list<Flow>|null
     */
    private function preliminaryTax(Company $company, array $deadlines, CarbonImmutable $today): ?array
    {
        $monthly = $company->preliminary_tax_monthly_ore;

        if (! $company->f_skatt || $monthly === null || $monthly <= 0) {
            return null;
        }

        $payments = array_values(array_filter($deadlines, fn (Deadline $deadline): bool => $deadline->type === DeadlineType::PreliminaryTax
            && ! $deadline->isRecommendation && $deadline->daysLeft($today) > 0));

        if ($payments === []) {
            return [];
        }

        // A month whose debit is already booked on 1630 is in the tax account's balance.
        $booked = VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->where('accounts.number', MoneyNotYours::TAX_ACCOUNT)
            ->where('voucher_rows.credit_ore', $monthly)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereDate('vouchers.voucher_date', '>=', $payments[0]->date->startOfMonth()->toDateString())
            ->whereDate('vouchers.voucher_date', '<=', $today->toDateString())
            ->toBase()
            ->pluck('vouchers.voucher_date')
            ->map(fn (mixed $date): string => substr((string) $date, 0, 7))
            ->all();

        return array_values(array_map(
            fn (Deadline $deadline): array => ['due' => $deadline->date->toDateString(), 'amount' => $monthly],
            array_filter($payments, fn (Deadline $deadline): bool => ! in_array($deadline->date->format('Y-m'), $booked, true)),
        ));
    }

    /**
     * The coming invoices of active agreements and the drafts agreements
     * have made, with VAT, on their due dates up to the end of the forecast.
     *
     * @return list<Flow>
     */
    private function recurring(CarbonImmutable $until): array
    {
        $flows = [];

        $agreements = RecurringInvoice::query()
            ->join('customers', 'customers.id', '=', 'recurring_invoices.customer_id')
            ->where('recurring_invoices.status', AgreementStatus::Active->value)
            ->whereDate('recurring_invoices.next_invoice_date', '<=', $until->toDateString())
            ->get(['recurring_invoices.id', 'recurring_invoices.next_invoice_date', 'recurring_invoices.end_date', 'recurring_invoices.interval_months', 'recurring_invoices.invoice_day', 'recurring_invoices.total_ore', 'customers.payment_terms_days']);

        foreach ($agreements as $agreement) {
            $terms = (int) $agreement->getAttribute('payment_terms_days');

            for ($date = $agreement->next_invoice_date; ! $date->greaterThan($until); $date = $agreement->dateAfter($date)) {
                if ($agreement->end_date !== null && $date->greaterThan($agreement->end_date)) {
                    break;
                }

                $due = $date->addDays($terms);

                if (! $due->greaterThan($until)) {
                    $flows[] = ['due' => $due->toDateString(), 'amount' => $agreement->total_ore->ore];
                }
            }
        }

        $drafts = Invoice::query()
            ->where('status', InvoiceStatus::Draft->value)
            ->where('kind', Invoice::INVOICE)
            ->whereNotNull('recurring_invoice_id')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $until->toDateString())
            ->toBase()
            ->groupBy('due_date')
            ->select('due_date', DB::raw('SUM(total_ore) AS amount'))
            ->get();

        foreach ($drafts as $row) {
            $flows[] = ['due' => substr((string) $row->due_date, 0, 10), 'amount' => (int) $row->amount];
        }

        return $flows;
    }

    /**
     * Null when payroll is not counted on its own: the module is off or
     * there is no run of the last months to go by.
     *
     * @return array{net: list<Flow>, tax: list<Flow>}|null
     */
    private function payroll(User $user, Company $company, ?MoneyNotYoursFigures $figures, CarbonImmutable $today, int $months): ?array
    {
        if (! $this->registry->has(PayrollModule::KEY) || ! $this->modules->isEnabled(PayrollModule::KEY, $company)) {
            return null;
        }

        $seesUnbooked = $user->hasTeamPermission($company, 'payroll:view');
        $runs = PayrollRun::query()
            ->leftJoin('payslips', 'payslips.payroll_run_id', '=', 'payroll_runs.id')
            ->where('payroll_runs.status', '<>', PayrollRun::DRAFT)
            ->when(! $seesUnbooked, fn ($query) => $query->whereNotNull('payroll_runs.posted_voucher_id'))
            ->whereDate('payroll_runs.payment_date', '>=', $today->startOfMonth()->subMonthsNoOverflow(self::PAYROLL_MONTHS)->toDateString())
            ->groupBy('payroll_runs.id', 'payroll_runs.type', 'payroll_runs.payment_date', 'payroll_runs.posted_voucher_id')
            ->orderBy('payroll_runs.payment_date')
            ->toBase()
            ->select('payroll_runs.type', 'payroll_runs.payment_date', 'payroll_runs.posted_voucher_id')
            ->selectRaw('COALESCE(SUM(payslips.net_ore), 0) AS net, COALESCE(SUM(payslips.tax_ore + payslips.employer_contribution_ore), 0) AS taxes')
            ->get()
            ->map(fn (object $row): array => [
                'type' => (string) $row->type,
                'payment_date' => CarbonImmutable::parse((string) $row->payment_date)->startOfDay(),
                'booked' => $row->posted_voucher_id !== null,
                'net' => (int) $row->net,
                'taxes' => (int) $row->taxes,
            ]);

        $reference = $runs->last(fn (array $run): bool => $run['type'] === 'REGULAR');

        if ($reference === null) {
            return null;
        }

        $large = AgiDates::isLarge($company);
        $agiPayment = fn (CarbonImmutable $paid): string => AgiDates::payment($paid->format('Y-m'), $large)->toDateString();
        $net = [];
        $tax = [];

        foreach ($runs->reject(fn (array $run): bool => $run['booked']) as $run) {
            $net[] = ['due' => $run['payment_date']->toDateString(), 'amount' => $run['net']];
            $tax[] = ['due' => $agiPayment($run['payment_date']), 'amount' => $run['taxes']];
        }

        // Tax and contributions already booked and not yet moved to the tax account.
        $liability = (int) ($figures?->employerTotal() ?? 0);

        if ($liability > 0) {
            $lastBooked = $runs->last(fn (array $run): bool => $run['booked']);
            $tax[] = ['due' => $agiPayment($lastBooked['payment_date'] ?? $today->subMonthNoOverflow()), 'amount' => $liability];
        }

        $paidMonths = $runs->filter(fn (array $run): bool => $run['type'] === 'REGULAR')->map(fn (array $run): string => $run['payment_date']->format('Y-m'))->all();
        $day = (int) ($this->modules->settings(PayrollModule::KEY, $company)['default_payment_day'] ?? 25);

        for ($i = 0; $i < $months; $i++) {
            $month = $today->startOfMonth()->addMonthsNoOverflow($i);

            if (in_array($month->format('Y-m'), $paidMonths, true)) {
                continue;
            }

            // A month without a run: like the latest run, on the company's payment day (not before today).
            $paid = PayrollRuns::paymentDateIn($month, $day);
            $paid = $paid->lessThan($today) ? $today : $paid;
            $net[] = ['due' => $paid->toDateString(), 'amount' => $reference['net']];
            $tax[] = ['due' => $agiPayment($paid), 'amount' => $reference['taxes']];
        }

        return ['net' => $net, 'tax' => $tax];
    }
}
