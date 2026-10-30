<?php

declare(strict_types=1);

namespace App\Livewire\Payables;

use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\PaySupplierInvoice;
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

    public function mount(SupplierInvoice $invoice): void
    {
        $this->invoiceId = $invoice->id;
        $this->paidOn = now()->toDateString();
        $this->amount = $invoice->remaining()->format(withCurrency: false);
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

        $payment = $pay($this->user(), $this->invoice(), $date, $amount);
        $invoice = $this->invoice();

        $this->amount = $invoice->remaining()->format(withCurrency: false);
        $this->toast($invoice->status === SupplierInvoiceStatus::Paid
            ? __(':title är betald.', ['title' => $invoice->title()])
            : __(':amount är registrerat. Kvar att betala: :remaining.', ['amount' => $payment->amount_ore->format(), 'remaining' => $invoice->remaining()->format()]));
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
