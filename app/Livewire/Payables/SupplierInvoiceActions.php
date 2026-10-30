<?php

declare(strict_types=1);

namespace App\Livewire\Payables;

use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\OffsetSupplierCredit;
use App\Actions\Payables\PaySupplierInvoice;
use App\Domain\Payables\SupplierInvoiceBooking;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Policies\SupplierInvoicePolicy;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * What can be done with a registered supplier invoice: approve it and
 * register what has been paid.
 */
class SupplierInvoiceActions extends Component
{
    use ShowsToasts;

    #[Locked]
    public string $invoiceId;

    public string $paidOn = '';

    public string $amount = '';

    public string $offsetInvoiceId = '';

    public function mount(SupplierInvoice $invoice): void
    {
        $this->invoiceId = $invoice->id;
        $this->paidOn = now()->toDateString();
        $this->amount = Money::ofOre(abs($invoice->remaining()->ore))->format(withCurrency: false);
    }

    public function approve(ApproveSupplierInvoice $approve): void
    {
        $invoice = $approve($this->user(), $this->invoice());

        $this->toast(__(':title är attesterad och kan betalas.', ['title' => $invoice->title()]));
    }

    public function pay(PaySupplierInvoice $pay): void
    {
        try {
            $amount = Money::parse($this->amount);
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->paidOn) ?: throw new InvalidArgumentException;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => __('Skriv datum och belopp, till exempel 1 250,00.')]);
        }

        $payment = $pay($this->user(), $this->invoice(), $date, $this->invoice()->isCredit() ? $amount->negated() : $amount);
        $invoice = $this->invoice();

        $this->amount = Money::ofOre(abs($invoice->remaining()->ore))->format(withCurrency: false);
        $this->toast($invoice->status === SupplierInvoiceStatus::Paid
            ? __(':title är betald.', ['title' => $invoice->title()])
            : __(':amount är registrerat. Kvar: :remaining.', ['amount' => Money::ofOre(abs($payment->amount_ore->ore))->format(), 'remaining' => Money::ofOre(abs($invoice->remaining()->ore))->format()]));
    }

    public function offset(OffsetSupplierCredit $offset): void
    {
        $invoice = SupplierInvoice::query()->find($this->offsetInvoiceId)
            ?? throw ValidationException::withMessages(['offset' => __('Välj en faktura att kvitta mot.')]);

        $amount = $offset($this->user(), $this->invoice(), $invoice, CarbonImmutable::today());

        $this->offsetInvoiceId = '';
        $this->amount = Money::ofOre(abs($this->invoice()->remaining()->ore))->format(withCurrency: false);
        $this->toast(__(':amount av :credit är kvittat mot :invoice.', ['amount' => Money::ofOre($amount)->format(), 'credit' => $this->invoice()->title(), 'invoice' => $invoice->title()]));
    }

    public function render(): View
    {
        $invoice = $this->invoice()->load(['payments.voucher.series', 'voucher.series', 'supplier', 'registeredBy:id,name', 'approvedBy:id,name', 'rows.account', 'rows.costCenter', 'rows.project']);

        return view('livewire.payables.supplier-invoice-actions', [
            'invoice' => $invoice,
            'canApprove' => $this->user()->can('approve', $invoice),
            'blockedBySegregation' => $invoice->status === SupplierInvoiceStatus::Registered
                && $this->user()->can('approveAny', [SupplierInvoice::class, $invoice])
                && (new SupplierInvoicePolicy)->blockedBySegregation($this->user(), $invoice),
            'canPay' => $this->user()->can('pay', $invoice),
            'buyerVat' => SupplierInvoiceBooking::buyerVat($invoice, $invoice->rows),
            'offsetInvoices' => $invoice->isCredit()
                ? SupplierInvoice::query()
                    ->where('supplier_id', $invoice->supplier_id)
                    ->where('kind', SupplierInvoice::INVOICE)
                    ->where('status', SupplierInvoiceStatus::Approved->value)
                    ->orderBy('due_date')
                    ->get()
                : collect(),
        ]);
    }

    private function invoice(): SupplierInvoice
    {
        return SupplierInvoice::query()->findOrFail($this->invoiceId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
