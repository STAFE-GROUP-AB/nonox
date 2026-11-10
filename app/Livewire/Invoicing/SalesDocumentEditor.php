<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Actions\Invoicing\ProgressSalesDocument;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Invoicing\SendSalesDocument;
use App\Livewire\Concerns\ShowsToasts;
use App\Livewire\Invoicing\Concerns\EditsSalesRows;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SalesDocument;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A quote or an order: written and changed while open, sent as a PDF, and
 * turned into an order or an invoice.
 */
class SalesDocumentEditor extends Component
{
    use EditsSalesRows;
    use ShowsToasts;

    #[Locked]
    public string $kind = SalesDocument::QUOTE;

    #[Locked]
    public ?string $documentId = null;

    public string $customerId = '';

    public string $documentDate = '';

    public string $untilDate = '';

    public string $yourReference = '';

    public string $ourReference = '';

    public string $notes = '';

    public string $email = '';

    public function mount(string $kind, ?SalesDocument $document = null): void
    {
        if ($document instanceof SalesDocument) {
            abort_unless(Gate::allows('view', $document), 403);

            $document->load(['rows.account', 'customer']);
            $this->kind = $document->kind;
            $this->documentId = $document->id;
            $this->customerId = (string) $document->customer_id;
            $this->documentDate = $document->document_date->toDateString();
            $this->untilDate = (string) $document->until_date?->toDateString();
            $this->yourReference = (string) $document->your_reference;
            $this->ourReference = (string) $document->our_reference;
            $this->notes = (string) $document->notes;
            $this->email = (string) ($document->sent_to ?? $document->customer?->email);
            $this->rows = $this->rowsFrom($document->rows);

            return;
        }

        $this->kind = $kind === SalesDocument::ORDER ? SalesDocument::ORDER : SalesDocument::QUOTE;
        abort_unless(Gate::allows('create', [SalesDocument::class, $this->company()]), 403);

        $this->documentDate = now()->toDateString();
        $this->untilDate = $this->kind === SalesDocument::QUOTE ? now()->addDays(30)->toDateString() : '';
        $this->rows = [$this->blankRow()];

        // ?kund=<id>: a new quote or order for that customer, as from the CRM card (ADR-043).
        $customer = Customer::query()->where('is_active', true)->whereNull('processing_restricted_at')->find(request()->integer('kund'));

        if ($customer instanceof Customer) {
            $this->customerId = (string) $customer->id;
            $this->email = (string) $customer->email;
        }
    }

    public function save(SaveSalesDocument $save): void
    {
        $this->resetErrorBag();

        $document = $save($this->user(), $this->company(), $this->kind, [
            'customer_id' => $this->customerId === '' ? null : (int) $this->customerId,
            'document_date' => $this->documentDate,
            'until_date' => $this->untilDate === '' ? null : $this->untilDate,
            'your_reference' => $this->yourReference,
            'our_reference' => $this->ourReference,
            'notes' => $this->notes,
            'rows' => $this->rowInput(),
        ], $this->documentId === null ? null : $this->document());

        if ($this->documentId === null) {
            Toast::flash(__(':title är sparad.', ['title' => $document->title()]));
            $this->redirectRoute($this->routeBase().'.show', ['document' => $document->id]);

            return;
        }

        // The saved rows come back with their keys, so a row keeps what hangs on it (ADR-071 §4) at the next save.
        $this->rows = $this->rowsFrom($document->load('rows.account')->rows);
        $this->dispatch('sales-document-saved');
        $this->toast(__(':title är sparad.', ['title' => $document->title()]));
    }

    public function send(SendSalesDocument $send): void
    {
        $send($this->user(), $this->document(), $this->email);

        $this->toast(__(':title är skickad till :email.', ['title' => $this->document()->title(), 'email' => $this->email]));
    }

    public function toOrder(ProgressSalesDocument $progress): void
    {
        $order = $progress->toOrder($this->user(), $this->document());

        Toast::flash(__('Offerten är godkänd och blev :title.', ['title' => $order->title()]));
        $this->redirectRoute('invoicing.orders.show', ['document' => $order->id]);
    }

    public function toInvoice(ProgressSalesDocument $progress): void
    {
        $invoice = $progress->toInvoice($this->user(), $this->document());

        Toast::flash(__('Ett fakturautkast är skapat från :title. Kontrollera det och skapa fakturan.', ['title' => $this->document()->title()]));
        $this->redirectRoute('invoicing.invoices.edit', ['invoice' => $invoice->id]);
    }

    public function cancel(ProgressSalesDocument $progress): void
    {
        $progress->cancel($this->user(), $this->document());

        $this->toast(__(':title är makulerad.', ['title' => $this->document()->title()]));
    }

    public function render(): View
    {
        $document = $this->documentId === null ? null : SalesDocument::query()->with(['invoice', 'fromQuote', 'orders'])->findOrFail($this->documentId);

        return view('livewire.invoicing.sales-document-editor', [
            'document' => $document,
            'isQuote' => $this->kind === SalesDocument::QUOTE,
            'editable' => $document === null || Gate::allows('update', $document),
            'canSend' => $document !== null && Gate::allows('send', $document),
            'customers' => Customer::query()->where('is_active', true)->whereNull('processing_restricted_at')->orderBy('name')->get(['id', 'number', 'name']),
            'articles' => $this->rowArticles(),
            'vatRules' => $this->salesVatRules(),
            'routeBase' => $this->routeBase(),
        ]);
    }

    private function routeBase(): string
    {
        return $this->kind === SalesDocument::QUOTE ? 'invoicing.quotes' : 'invoicing.orders';
    }

    private function document(): SalesDocument
    {
        return SalesDocument::query()->findOrFail($this->documentId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
