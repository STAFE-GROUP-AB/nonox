<?php

declare(strict_types=1);

namespace App\Domain\Insights;

use App\Domain\Accounting\VoucherStatus;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\SalesDocumentStatus;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\OpeningBalance;
use App\Models\SalesDocument;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\VoucherRow;
use App\Policies\SupplierInvoicePolicy;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The key figures a business owner wants in view on every page: what was
 * invoiced, what customers owe, what is about to be invoiced, the result so
 * far, the money in the bank and the VAT to pay. Each group is one query,
 * and a user only sees the groups their role may read.
 */
final class BusinessKpis
{
    /**
     * Account ranges read from the ledger (BAS): revenue and expenses, cash
     * and bank, and the VAT accounts.
     */
    private const array RESULT = [3000, 8999];

    private const array REVENUE = [3000, 3999];

    private const array CASH = [1900, 1999];

    private const array VAT = [2600, 2699];

    public const string SALES = 'sales';

    public const string PAYABLES = 'payables';

    public const string LEDGER = 'ledger';

    /**
     * The figures of the given groups that the user may see: sales under
     * Försäljning, payables under Inköp, the ledger under Bokföring, all of
     * them on the overview.
     *
     * @param  list<string>  $groups
     * @return list<array{
     *     label: string,
     *     value: Money,
     *     hint: ?string,
     *     warn: bool,
     *     link: ?string
     * }>
     */
    public function for(User $user, Company $company, array $groups = [self::SALES, self::PAYABLES, self::LEDGER], ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $kpis = [];

        if (in_array(self::SALES, $groups, true) && $user->hasTeamPermission($company, 'invoices:view')) {
            array_push($kpis, ...$this->sales($today));
        }

        if (in_array(self::PAYABLES, $groups, true) && SupplierInvoicePolicy::canSeePayables($user, $company)) {
            array_push($kpis, ...$this->payables($today));
        }

        if (in_array(self::LEDGER, $groups, true) && $user->hasTeamPermission($company, 'reports:view')) {
            array_push($kpis, ...$this->ledger($today, $user->hasTeamPermission($company, 'vat:prepare')));
        }

        return $kpis;
    }

    /**
     * @return list<array{label: string, value: Money, hint: ?string, warn: bool, link: ?string}>
     */
    private function sales(CarbonImmutable $today): array
    {
        $month = $today->startOfMonth();
        $previous = $month->subMonth();
        $issued = InvoiceStatus::Issued->value;

        $invoices = Invoice::query()
            ->toBase()
            ->selectRaw('COALESCE(SUM(net_ore) FILTER (WHERE status <> ? AND invoice_date BETWEEN ? AND ?), 0) AS this_month', [InvoiceStatus::Draft->value, $month->toDateString(), $month->endOfMonth()->toDateString()])
            ->selectRaw('COALESCE(SUM(net_ore) FILTER (WHERE status <> ? AND invoice_date BETWEEN ? AND ?), 0) AS last_month', [InvoiceStatus::Draft->value, $previous->toDateString(), $previous->endOfMonth()->toDateString()])
            ->selectRaw('COALESCE(SUM(total_ore - paid_ore) FILTER (WHERE status = ? AND kind = ?), 0) AS outstanding', [$issued, Invoice::INVOICE])
            ->selectRaw('COALESCE(SUM(total_ore - paid_ore) FILTER (WHERE status = ? AND kind = ? AND due_date < ?), 0) AS overdue', [$issued, Invoice::INVOICE, $today->toDateString()])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ? AND kind = ? AND due_date < ?) AS overdue_count', [$issued, Invoice::INVOICE, $today->toDateString()])
            ->first();

        $documents = SalesDocument::query()
            ->toBase()
            ->selectRaw('COALESCE(SUM(net_ore) FILTER (WHERE kind = ?), 0) AS orders', [SalesDocument::ORDER])
            ->selectRaw('COALESCE(SUM(net_ore) FILTER (WHERE kind = ?), 0) AS quotes', [SalesDocument::QUOTE])
            ->where('status', SalesDocumentStatus::Open->value)
            ->first();

        $overdueCount = (int) ($invoices->overdue_count ?? 0);

        return [
            [
                'label' => __('Fakturerat i :month', ['month' => $today->translatedFormat('F')]),
                'value' => Money::ofOre((int) ($invoices->this_month ?? 0)),
                'hint' => __('Exkl. moms. Förra månaden: :amount', ['amount' => Money::ofOre((int) ($invoices->last_month ?? 0))->format()]),
                'warn' => false,
                'link' => route('invoicing.invoices.index'),
            ],
            [
                'label' => __('Obetalt hos kunder'),
                'value' => Money::ofOre((int) ($invoices->outstanding ?? 0)),
                'hint' => $overdueCount === 0
                    ? __('Inget har förfallit.')
                    : __($overdueCount === 1 ? ':count faktura har förfallit, :amount' : ':count fakturor har förfallit, :amount', [
                        'count' => $overdueCount,
                        'amount' => Money::ofOre((int) ($invoices->overdue ?? 0))->format(),
                    ]),
                'warn' => $overdueCount > 0,
                'link' => route('invoicing.invoices.index', ['visa' => $overdueCount > 0 ? 'forfallna' : 'obetalda']),
            ],
            [
                'label' => __('Order att fakturera'),
                'value' => Money::ofOre((int) ($documents->orders ?? 0)),
                'hint' => __('Exkl. moms. Öppna offerter: :amount', ['amount' => Money::ofOre((int) ($documents->quotes ?? 0))->format()]),
                'warn' => false,
                'link' => route('invoicing.orders'),
            ],
        ];
    }

    /**
     * @return list<array{label: string, value: Money, hint: ?string, warn: bool, link: ?string}>
     */
    private function payables(CarbonImmutable $today): array
    {
        $month = $today->startOfMonth();
        $previous = $month->subMonth();
        $registered = SupplierInvoiceStatus::Registered->value;
        $open = [$registered, SupplierInvoiceStatus::Approved->value];

        $invoices = SupplierInvoice::query()
            ->toBase()
            ->selectRaw('COALESCE(SUM(total_ore - paid_ore) FILTER (WHERE status IN (?, ?)), 0) AS unpaid', $open)
            ->selectRaw('COALESCE(SUM(total_ore - paid_ore) FILTER (WHERE status IN (?, ?) AND due_date < ?), 0) AS overdue', [...$open, $today->toDateString()])
            ->selectRaw('COUNT(*) FILTER (WHERE status IN (?, ?) AND due_date < ?) AS overdue_count', [...$open, $today->toDateString()])
            ->selectRaw('COALESCE(SUM(total_ore) FILTER (WHERE status = ?), 0) AS awaiting', [$registered])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS awaiting_count', [$registered])
            ->selectRaw('COALESCE(SUM(total_ore - vat_ore) FILTER (WHERE status <> ? AND invoice_date BETWEEN ? AND ?), 0) AS this_month', [SupplierInvoiceStatus::Draft->value, $month->toDateString(), $month->endOfMonth()->toDateString()])
            ->selectRaw('COALESCE(SUM(total_ore - vat_ore) FILTER (WHERE status <> ? AND invoice_date BETWEEN ? AND ?), 0) AS last_month', [SupplierInvoiceStatus::Draft->value, $previous->toDateString(), $previous->endOfMonth()->toDateString()])
            ->first();

        $overdueCount = (int) ($invoices->overdue_count ?? 0);
        $awaitingCount = (int) ($invoices->awaiting_count ?? 0);

        return [
            [
                'label' => __('Inköpt i :month', ['month' => $today->translatedFormat('F')]),
                'value' => Money::ofOre((int) ($invoices->this_month ?? 0)),
                'hint' => __('Exkl. moms. Förra månaden: :amount', ['amount' => Money::ofOre((int) ($invoices->last_month ?? 0))->format()]),
                'warn' => false,
                'link' => route('payables.invoices.index'),
            ],
            [
                'label' => __('Att betala till leverantörer'),
                'value' => Money::ofOre((int) ($invoices->unpaid ?? 0)),
                'hint' => $overdueCount === 0
                    ? __('Inget har förfallit.')
                    : __($overdueCount === 1 ? ':count faktura har förfallit, :amount' : ':count fakturor har förfallit, :amount', [
                        'count' => $overdueCount,
                        'amount' => Money::ofOre((int) ($invoices->overdue ?? 0))->format(),
                    ]),
                'warn' => $overdueCount > 0,
                'link' => route('payables.invoices.index', ['visa' => $overdueCount > 0 ? 'forfallna' : 'att-betala']),
            ],
            [
                'label' => __('Väntar på attest'),
                'value' => Money::ofOre((int) ($invoices->awaiting ?? 0)),
                'hint' => $awaitingCount === 0
                    ? __('Inget väntar.')
                    : __($awaitingCount === 1 ? ':count faktura väntar på attest.' : ':count fakturor väntar på attest.', ['count' => $awaitingCount]),
                'warn' => false,
                'link' => route('payables.invoices.index', ['visa' => 'attest']),
            ],
        ];
    }

    /**
     * @return list<array{label: string, value: Money, hint: ?string, warn: bool, link: ?string}>
     */
    private function ledger(CarbonImmutable $today, bool $canSeeVat): array
    {
        $year = FiscalYear::query()
            ->whereDate('start_date', '<=', $today->toDateString())
            ->whereDate('end_date', '>=', $today->toDateString())
            ->first();

        if (! $year instanceof FiscalYear) {
            return [];
        }

        $movements = VoucherRow::query()
            ->toBase()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->where('vouchers.fiscal_year_id', $year->id)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->selectRaw('COALESCE(SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) FILTER (WHERE accounts.number BETWEEN ? AND ?), 0) AS result', self::RESULT)
            ->selectRaw('COALESCE(SUM(voucher_rows.credit_ore - voucher_rows.debit_ore) FILTER (WHERE accounts.number BETWEEN ? AND ?), 0) AS revenue', self::REVENUE)
            ->selectRaw('COALESCE(SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) FILTER (WHERE accounts.number BETWEEN ? AND ?), 0) AS cash', self::CASH)
            ->selectRaw('COALESCE(SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) FILTER (WHERE accounts.number BETWEEN ? AND ?), 0) AS vat', self::VAT)
            ->first();

        $opening = OpeningBalance::query()
            ->toBase()
            ->join('accounts', 'accounts.id', '=', 'opening_balances.account_id')
            ->where('opening_balances.fiscal_year_id', $year->id)
            ->selectRaw('COALESCE(SUM(opening_balances.amount_ore) FILTER (WHERE accounts.number BETWEEN ? AND ?), 0) AS cash', self::CASH)
            ->selectRaw('COALESCE(SUM(opening_balances.amount_ore) FILTER (WHERE accounts.number BETWEEN ? AND ?), 0) AS vat', self::VAT)
            ->first();

        $result = -(int) ($movements->result ?? 0);
        $vatBalance = -((int) ($movements->vat ?? 0) + (int) ($opening->vat ?? 0));

        return [
            [
                'label' => __('Resultat hittills :year', ['year' => $year->label()]),
                'value' => Money::ofOre($result),
                'hint' => __('Intäkter: :amount', ['amount' => Money::ofOre((int) ($movements->revenue ?? 0))->format()]),
                'warn' => $result < 0,
                'link' => route('accounting.reports'),
            ],
            [
                'label' => __('Pengar på bank och kassa'),
                'value' => Money::ofOre((int) ($movements->cash ?? 0) + (int) ($opening->cash ?? 0)),
                'hint' => __('Saldo enligt bokföringen, konto 1900–1999.'),
                'warn' => false,
                'link' => route('accounting.reports'),
            ],
            [
                'label' => $vatBalance >= 0 ? __('Moms att betala') : __('Moms att få tillbaka'),
                'value' => Money::ofOre(abs($vatBalance)),
                'hint' => __('Saldo på momskontona, konto 2600–2699.'),
                'warn' => false,
                'link' => $canSeeVat ? route('vat.index') : null,
            ],
        ];
    }
}
