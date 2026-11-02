<?php

declare(strict_types=1);

namespace App\Domain\Overview\Todos;

use App\Domain\Company\CompanyOverview;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Overview\Contracts\TodoProvider;
use App\Domain\Overview\TodoItem;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\VatReport;
use App\Models\Voucher;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What waits in the company according to Mina företag (CompanyOverview):
 * VAT returns, employer declarations, years to close, overdue customer
 * invoices, approvals, payments, drafts and payroll. The same counts and the
 * same permission rules, for the current company only, turned into items
 * with a link to the right page. An overdue customer invoice is listed by
 * itself when there are only a few.
 */
final class CompanyTasks implements TodoProvider
{
    /** Up to this many overdue invoices are listed one by one. */
    public const int SINGLE_INVOICES = 3;

    public function __construct(private readonly CompanyOverview $overview) {}

    public function todos(User $user, Company $company, CarbonImmutable $today): array
    {
        $row = $this->overview->for($user, $today, $company)[0] ?? null;

        if ($row === null) {
            return [];
        }

        $items = [];

        foreach ($row['tasks'] as $task) {
            array_push($items, ...$this->items($task, $today));
        }

        return $items;
    }

    public function invalidatedBy(): array
    {
        return [Voucher::class, Invoice::class, SupplierInvoice::class, VatReport::class, FiscalYear::class];
    }

    /**
     * @param  array{key: string, permission: string, label: string, count: int, destination: string, urgent: bool, due: ?string, overdue: bool}  $task
     * @return list<TodoItem>
     */
    private function items(array $task, CarbonImmutable $today): array
    {
        $count = $task['count'];
        $due = $task['due'] === null ? null : CarbonImmutable::parse($task['due']);
        $url = route(CompanyOverview::DESTINATIONS[$task['destination']] ?? 'dashboard');

        return match ($task['permission']) {
            'vat:prepare' => [new TodoItem(
                'vat',
                'vat',
                $count === 1 ? __('Momsdeklaration att lämna') : __(':count momsdeklarationer att lämna', ['count' => $count]),
                $due === null ? __('Perioden har tagit slut.') : __('Sista dag :date', ['date' => $due->translatedFormat('j F')]),
                $url,
                TodoItem::PRIORITY_VAT,
                $due,
            )],
            'payroll:agi-submit' => [new TodoItem(
                'agi',
                'payroll',
                $count === 1 ? __('Arbetsgivardeklaration att lämna') : __(':count arbetsgivardeklarationer att lämna', ['count' => $count]),
                $due === null ? $task['label'] : __('Sista dag :date', ['date' => $due->translatedFormat('j F')]),
                $url,
                TodoItem::PRIORITY_AGI,
                $due,
            )],
            'invoices:view' => $this->overdueInvoices($count, $today),
            'supplier-invoices:approve' => [new TodoItem(
                'approve',
                'approve',
                $count === 1 ? __('1 leverantörsfaktura att attestera') : __(':count leverantörsfakturor att attestera', ['count' => $count]),
                __('Attesten behövs innan fakturan kan betalas.'),
                route('payables.invoices.index', ['visa' => 'attest']),
                TodoItem::PRIORITY_APPROVE,
            )],
            'payments:create' => [new TodoItem(
                'pay',
                'pay',
                $count === 1 ? __('1 leverantörsfaktura att betala') : __(':count leverantörsfakturor att betala', ['count' => $count]),
                __('Förfaller inom en vecka eller har redan förfallit.'),
                route('payables.invoices.index', ['visa' => 'att-betala']),
                TodoItem::PRIORITY_PAY,
            )],
            'vouchers:create' => [new TodoItem(
                'drafts',
                'draft',
                $count === 1 ? __('1 verifikation i utkast') : __(':count verifikationer i utkast', ['count' => $count]),
                __('Utkast räknas inte i bokföringen förrän de bokförs.'),
                route('accounting.vouchers.index', ['visa' => 'utkast']),
                TodoItem::PRIORITY_DRAFTS,
            )],
            'payroll:approve' => [new TodoItem(
                'payroll-runs',
                'payroll',
                $count === 1 ? __('1 lönekörning att attestera') : __(':count lönekörningar att attestera', ['count' => $count]),
                __('Lönen kan betalas ut när körningen är attesterad.'),
                $url,
                TodoItem::PRIORITY_PAYROLL,
            )],
            'payroll:run' => [new TodoItem(
                'payroll-requests',
                'payroll',
                $count === 1 ? __('1 ansökan eller utlägg att besvara') : __(':count ansökningar och utlägg att besvara', ['count' => $count]),
                __('Frånvaro och utlägg som personalen har skickat in.'),
                $url,
                TodoItem::PRIORITY_PAYROLL + 1,
            )],
            'fiscal-years:manage' => [new TodoItem(
                'closing',
                'calendar',
                $count === 1 ? __('Räkenskapsår att stänga') : __(':count räkenskapsår att stänga', ['count' => $count]),
                __('Året har tagit slut. Gör bokslutet och stäng året.'),
                $url,
                TodoItem::PRIORITY_CLOSING,
            )],
            default => [new TodoItem($task['key'], 'task', $task['label'], null, $url, TodoItem::PRIORITY_OTHER, $due)],
        };
    }

    /**
     * The oldest overdue invoices one by one, with customer and amount, when
     * there are only a few; otherwise one line for all of them.
     *
     * @return list<TodoItem>
     */
    private function overdueInvoices(int $count, CarbonImmutable $today): array
    {
        if ($count > self::SINGLE_INVOICES) {
            $total = (int) Invoice::query()
                ->where('status', InvoiceStatus::Issued->value)
                ->where('kind', Invoice::INVOICE)
                ->whereDate('due_date', '<', $today->toDateString())
                ->sum(DB::raw('total_ore - paid_ore'));

            return [new TodoItem(
                'overdue-invoices',
                'invoice',
                __(':count kundfakturor har förfallit', ['count' => $count]),
                __('Sammanlagt :amount obetalt. Skicka en påminnelse.', ['amount' => Money::ofOre($total)->format()]),
                route('invoicing.invoices.index', ['visa' => 'forfallna']),
                TodoItem::PRIORITY_OVERDUE_INVOICE,
            )];
        }

        $invoices = Invoice::query()
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->where('invoices.status', InvoiceStatus::Issued->value)
            ->where('invoices.kind', Invoice::INVOICE)
            ->whereDate('invoices.due_date', '<', $today->toDateString())
            ->orderBy('invoices.due_date')
            ->orderBy('invoices.number')
            ->limit(self::SINGLE_INVOICES)
            ->get(['invoices.id', 'invoices.number', 'invoices.kind', 'invoices.due_date', 'invoices.total_ore', 'invoices.paid_ore', 'customers.name as customer_name']);

        return array_values($invoices->map(fn (Invoice $invoice): TodoItem => new TodoItem(
            "invoice:{$invoice->id}",
            'invoice',
            __('Faktura :number är förfallen', ['number' => $invoice->number]),
            __(':customer, :amount', ['customer' => (string) $invoice->getAttribute('customer_name'), 'amount' => $invoice->total_ore->minus($invoice->paid_ore)->format()]),
            route('invoicing.invoices.show', $invoice->id),
            TodoItem::PRIORITY_OVERDUE_INVOICE,
            $invoice->due_date,
        ))->all());
    }
}
