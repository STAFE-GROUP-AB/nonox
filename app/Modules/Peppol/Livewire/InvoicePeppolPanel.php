<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Modules\Peppol\Actions\LookupParticipant;
use App\Modules\Peppol\Actions\SendInvoiceViaPeppol;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\Models\PeppolTransmission;
use App\Modules\Peppol\Support\AccessPoints;
use App\Support\Peppol\UblInvoiceWriter;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Skicka som e-faktura" on an issued invoice: the customer's Peppol ID, a
 * check that the customer can receive it, sending, and how earlier sendings
 * went. Shown only while the Peppol module is on.
 */
class InvoicePeppolPanel extends Component
{
    use ShowsToasts;

    public const string NAME = 'peppol.invoice-panel';

    #[Locked]
    public string $invoiceId;

    /** The result of the last lookup, in Swedish, or empty. */
    public string $lookupResult = '';

    public bool $lookupOk = false;

    public function mount(Invoice $invoice): void
    {
        // Read again inside the current company: another company's invoice answers 404.
        $this->invoiceId = Invoice::query()->findOrFail($invoice->id)->id;
    }

    public function check(LookupParticipant $lookup): void
    {
        $customer = $this->customer();
        $result = $lookup($this->user(), $this->company(), (string) $customer->peppol_id);

        $this->lookupOk = $result->acceptsBilling();
        $this->lookupResult = match (true) {
            ! $result->registered => __(':id finns inte i Peppol. Kontrollera Peppol-ID:t med kunden.', ['id' => $result->participant->toString()]),
            ! $result->acceptsInvoice => __(':id finns i Peppol men tar inte emot fakturor enligt Peppol BIS Billing 3.0.', ['id' => $result->participant->toString()]),
            default => __(':id tar emot e-fakturor via Peppol.', ['id' => $result->participant->toString()]),
        };
    }

    public function send(SendInvoiceViaPeppol $send): void
    {
        $send($this->user(), $this->invoice());
        $this->loaded = null;

        $this->toast(__(':title är på väg till kunden via Peppol.', ['title' => $this->invoice()->title()]));
    }

    public function render(): View
    {
        $invoice = $this->invoice();
        $company = $this->company();

        return view('peppol::invoice-panel', [
            'invoice' => $invoice,
            'customer' => $this->customer(),
            'transmissions' => PeppolTransmission::query()->where('invoice_id', $invoice->id)->orderByDesc('queued_at')->get(),
            'participant' => PeppolParticipant::query()->first(),
            'unsupported' => UblInvoiceWriter::unsupportedReason($invoice, $company),
            'canSend' => $this->user()->can('send', $invoice),
            'paused' => AccessPoints::isPaused(),
        ]);
    }

    private ?Invoice $loaded = null;

    /** Read once per request (inside the current company: another company's invoice answers 404). */
    private function invoice(): Invoice
    {
        return $this->loaded ??= Invoice::query()->findOrFail($this->invoiceId);
    }

    private function customer(): Customer
    {
        return Customer::query()->findOrFail($this->invoice()->customer_id);
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->invoice()->company_id);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
