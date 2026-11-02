<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\RegisterTaxReductionPayment;
use App\Actions\Invoicing\SendInvoice;
use App\Actions\Invoicing\SendReminder;
use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Invoicing\InvoiceStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Currency\ExchangeRate;
use App\Support\Currency\RiksbankRates;
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
 * credit it. A payment of an invoice in another currency is entered in that
 * currency; the kronor that reached the bank are prefilled at Riksbanken's
 * rate on the payment day, to be corrected from the bank statement.
 */
class InvoiceActions extends Component
{
    use ShowsToasts;

    #[Locked]
    public string $invoiceId;

    public string $email = '';

    public string $paidOn = '';

    public string $amount = '';

    /** For an invoice in another currency: how much of it is paid, in that currency. */
    public string $foreignAmount = '';

    /** The date and rate of Riksbanken's rate the kronor were prefilled with, or empty. */
    public string $rateDate = '';

    public function mount(Invoice $invoice): void
    {
        $this->invoiceId = $invoice->id;
        $this->email = (string) ($invoice->sent_to ?? $invoice->customer_snapshot['email'] ?? '');
        $this->paidOn = now()->toDateString();
        $this->resetAmounts($invoice);
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

    public function updatedPaidOn(): void
    {
        $this->prefillKronor($this->invoice());
    }

    public function updatedForeignAmount(): void
    {
        $this->prefillKronor($this->invoice());
    }

    public function registerPayment(RegisterPayment $register): void
    {
        try {
            $amount = Money::parse($this->amount);
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->paidOn) ?: throw new InvalidArgumentException;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => __('Skriv datum och belopp, till exempel 1 250,00.')]);
        }

        $foreign = null;

        if ($this->invoice()->isForeign()) {
            try {
                $foreign = Money::parse($this->foreignAmount)->ore;
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['foreign_amount' => __('Skriv beloppet i :currency, till exempel 1 517,50.', ['currency' => $this->invoice()->invoice_currency])]);
            }
        }

        $payment = $register($this->user(), $this->invoice(), $date, $amount, foreignAmount: $foreign);
        $invoice = $this->invoice();

        $this->resetAmounts($invoice);
        $this->toast($invoice->status === InvoiceStatus::Paid
            ? __(':title är betald.', ['title' => $invoice->title()])
            : __(':amount är registrerat. Kvar att betala: :remaining.', [
                'amount' => $payment->foreign_amount_minor !== null ? $invoice->formatForeign($payment->foreign_amount_minor) : $payment->amount_ore->format(),
                'remaining' => $invoice->formatRemaining(),
            ]));
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
            'canCredit' => $this->user()->can('credit', $invoice) && $invoice->creditInvoices->doesntContain(fn (Invoice $credit): bool => $credit->isDraft()),
            'exchangePreview' => $this->exchangePreview($invoice),
        ]);
    }

    /**
     * For an invoice in another currency: the kronor of the receivable that
     * the payment typed settles, and the exchange difference against the
     * kronor received (positive a gain). Null when the amounts cannot be read.
     *
     * @return ?array{settled: int, difference: int}
     */
    private function exchangePreview(Invoice $invoice): ?array
    {
        if (! $invoice->isForeign() || $invoice->foreignRemaining() === 0) {
            return null;
        }

        try {
            $foreign = Money::parse($this->foreignAmount)->ore;
            $received = Money::parse($this->amount)->ore;
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($foreign <= 0 || $foreign > (int) $invoice->foreignRemaining()) {
            return null;
        }

        $settled = InvoiceBooking::settledShare($invoice, $foreign);

        return ['settled' => $settled, 'difference' => $received - $settled];
    }

    /** What is left, in kronor, or for an invoice in another currency in that currency with the kronor prefilled. */
    private function resetAmounts(Invoice $invoice): void
    {
        $this->amount = $invoice->remaining()->format(withCurrency: false);

        if (! $invoice->isForeign()) {
            return;
        }

        $this->foreignAmount = Money::ofOre((int) $invoice->foreignRemaining())->format(withCurrency: false);
        $this->prefillKronor($invoice);
    }

    /** The kronor for the foreign amount at Riksbanken's rate on the payment day; empty when there is no rate to use. */
    private function prefillKronor(Invoice $invoice): void
    {
        $this->rateDate = '';

        if (! $invoice->isForeign() || $invoice->foreignRemaining() === 0 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->paidOn) !== 1) {
            return;
        }

        try {
            $foreign = Money::parse($this->foreignAmount)->ore;
        } catch (InvalidArgumentException) {
            return;
        }

        $rate = app(RiksbankRates::class)->rate($invoice->invoice_currency, $this->paidOn);

        if (! $rate instanceof ExchangeRate) {
            $this->amount = '';

            return;
        }

        $this->amount = Money::ofOre($rate->toOre($foreign))->format(withCurrency: false);
        $this->rateDate = $rate->date.' · '.$rate->format();
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
