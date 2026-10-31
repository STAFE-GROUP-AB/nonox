<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\RegisterTaxReductionPayment;
use App\Actions\Invoicing\SendInvoice;
use App\Actions\Invoicing\SendReminder;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * What can be done with an issued invoice: send it, register a payment,
 * credit it.
 */
class InvoiceActions extends Component
{
    use ShowsToasts;

    #[Locked]
    public string $invoiceId;

    public string $email = '';

    public string $paidOn = '';

    public string $amount = '';

    public function mount(Invoice $invoice): void
    {
        $this->invoiceId = $invoice->id;
        $this->email = (string) ($invoice->sent_to ?? $invoice->customer_snapshot['email'] ?? '');
        $this->paidOn = now()->toDateString();
        $this->amount = $invoice->remaining()->format(withCurrency: false);
        $this->taxPaidOn = now()->toDateString();
        $this->taxAmount = Money::ofOre($invoice->tax_reduction_ore->ore - $invoice->tax_reduction_paid_ore->ore)->format(withCurrency: false);
    }

    public function send(SendInvoice $send): void
    {
        $send($this->user(), $this->invoice(), $this->email);

        $this->toast(__(':title är skickad till :email.', ['title' => $this->invoice()->title(), 'email' => $this->email]));
    }

    public function remind(SendReminder $remind): void
    {
        $reminder = $remind($this->user(), $this->invoice(), $this->email);

        $this->toast(__('Påminnelse :sequence är skickad till :email. Ny sista betaldag: :date.', [
            'sequence' => $reminder->sequence,
            'email' => $reminder->sent_to,
            'date' => $reminder->pay_by->toDateString(),
        ]));
    }

    public function registerPayment(RegisterPayment $register): void
    {
        try {
            $amount = Money::parse($this->amount);
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->paidOn) ?: throw new InvalidArgumentException;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => __('Skriv datum och belopp, till exempel 1 250,00.')]);
        }

        $payment = $register($this->user(), $this->invoice(), $date, $amount);
        $invoice = $this->invoice();

        $this->amount = $invoice->remaining()->format(withCurrency: false);
        $this->toast($invoice->remaining()->isZero()
            ? __(':title är betald.', ['title' => $invoice->title()])
            : __(':amount är registrerat. Kvar att betala: :remaining.', ['amount' => $payment->amount_ore->format(), 'remaining' => $invoice->remaining()->format()]));
    }

    public string $taxPaidOn = '';

    public string $taxAmount = '';

    public function registerTaxReductionPayment(RegisterTaxReductionPayment $register): void
    {
        try {
            $amount = Money::parse($this->taxAmount);
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->taxPaidOn) ?: throw new InvalidArgumentException;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['tax_amount' => __('Skriv datum och belopp, till exempel 1 250,00.')]);
        }

        $invoice = $register($this->user(), $this->invoice(), $date, $amount);
        $left = Money::ofOre($invoice->tax_reduction_ore->ore - $invoice->tax_reduction_paid_ore->ore);
        $this->taxAmount = $left->isZero() ? '' : $left->format(withCurrency: false);
        $this->toast(__('Skatteverkets betalning på :amount är registrerad.', ['amount' => $amount->format()]));
    }

    public function credit(CreditInvoice $credit): void
    {
        $draft = $credit($this->user(), $this->invoice());

        Toast::flash(__('Kreditfakturan är förberedd. Kontrollera den och skapa den.'));
        $this->redirectRoute('invoicing.invoices.edit', ['invoice' => $draft->id]);
    }

    public function render(): View
    {
        $invoice = $this->invoice()->load(['payments.voucher.series', 'voucher.series', 'credits', 'creditInvoices', 'reminders']);

        return view('livewire.invoicing.invoice-actions', [
            'invoice' => $invoice,
            'canSend' => $this->user()->can('send', $invoice),
            'canPay' => $this->user()->can('registerPayment', $invoice),
            'canTaxPay' => $this->user()->can('registerTaxReductionPayment', $invoice),
            'canRemind' => $this->user()->can('remind', $invoice),
            'canCredit' => $this->user()->can('credit', $invoice) && $invoice->creditInvoices->isEmpty(),
        ]);
    }

    private function invoice(): Invoice
    {
        return Invoice::query()->findOrFail($this->invoiceId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
