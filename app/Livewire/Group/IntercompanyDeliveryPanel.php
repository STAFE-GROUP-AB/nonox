<?php

declare(strict_types=1);

namespace App\Livewire\Group;

use App\Actions\Group\DeliverIntercompanyInvoice;
use App\Domain\Group\IntercompanyDeliveryStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\IntercompanyCounterparty;
use App\Models\IntercompanyDelivery;
use App\Models\Invoice;
use App\Models\User;
use App\Policies\IntercompanyDeliveryPolicy;
use App\Support\Peppol\UblInvoiceWriter;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Leverera till koncernföretaget" on an issued invoice (ADR-081 E27):
 * shown when the customer is marked as a group company. Delivers the
 * invoice into the group company's NONOX inbox, and lists the company's own
 * earlier attempts. Thin: the action decides; the panel never says why a
 * delivery was refused, because the sender may not learn it.
 */
class IntercompanyDeliveryPanel extends Component
{
    use ShowsToasts;

    public const string NAME = 'group.intercompany-delivery-panel';

    #[Locked]
    public string $invoiceId;

    public function mount(string $invoiceId): void
    {
        // Read again inside the current company: another company's invoice answers 404.
        $this->invoiceId = Invoice::query()->findOrFail($invoiceId)->id;
    }

    public function deliver(DeliverIntercompanyInvoice $deliver): void
    {
        $delivery = $deliver($this->user(), $this->invoice());

        $delivery->status === IntercompanyDeliveryStatus::Delivered
            ? $this->toast(__(':title är levererad till koncernföretagets inkorg.', ['title' => $this->invoice()->title()]))
            : $this->toast(DeliverIntercompanyInvoice::refusedMessage(), 'error');
    }

    public function render(): View
    {
        $invoice = $this->invoice();
        $company = Company::query()->findOrFail($invoice->company_id);
        $deliveries = IntercompanyDelivery::query()->where('invoice_id', $invoice->id)->with('deliverer:id,name')->latest('id')->get();

        return view('livewire.group.intercompany-delivery-panel', [
            'invoice' => $invoice,
            'marking' => IntercompanyCounterparty::query()->where('customer_id', $invoice->customer_id)->first(),
            'deliveries' => $deliveries,
            'delivered' => $deliveries->contains(fn (IntercompanyDelivery $delivery): bool => $delivery->status === IntercompanyDeliveryStatus::Delivered),
            'unsupported' => UblInvoiceWriter::unsupportedReason($invoice, $company),
            'canDeliver' => IntercompanyDeliveryPolicy::mayDeliver($this->user(), $invoice),
        ]);
    }

    private ?Invoice $loaded = null;

    private function invoice(): Invoice
    {
        return $this->loaded ??= Invoice::query()->findOrFail($this->invoiceId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
