<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Actions\Invoicing\ProgressSalesDocument;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Invoicing\SendSalesDocument;
use App\Domain\Pricing\PriceContext;
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
 * turned into an order or an invoice. It is written in a currency (ADR-082
 * decision 13), the customer's by default, and its article rows are priced
 * for the customer on the document date (ADR-082 P3).
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

    /** 'order' or 'invoice' while an expired quote asks which prices apply (ADR-082 §6). */
    #[Locked]
    public ?string $expiredChoice = null;

    /** @var ?list<array{index: int, line: int, description: string, old: string, new: string, source: string, price: string, discount: string, price_source: string, price_explanation: string, price_warnings: string}> "Hämta dagens priser": what would change */
    #[Locked]
    public ?array $todaysDiff = null;

    /** ISO 4217; the row prices are written in it. */
    public string $currency = 'SEK';

    public function mount(string $kind, ?SalesDocument $document = null): void
    {
        if ($document instanceof SalesDocument) {
            abort_unless(Gate::allows('view', $document), 403);

            $document->load(['rows.account', 'rows.costCenter', 'rows.project', 'rows.article:id,number', 'customer']);
            $this->kind = $document->kind;
            $this->documentId = $document->id;
            $this->customerId = (string) $document->customer_id;
            $this->documentDate = $document->document_date->toDateString();
            $this->untilDate = (string) $document->until_date?->toDateString();
            $this->yourReference = (string) $document->your_reference;
            $this->ourReference = (string) $document->our_reference;
            $this->notes = (string) $document->notes;
            $this->email = (string) ($document->sent_to ?? $document->customer?->email);
            $this->currency = $document->currency;
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
            $this->currency = self::offered((string) $customer->currency);
        }
    }

    /** A new choice of customer brings that customer's currency. */
    public function updatedCustomerId(): void
    {
        $customer = $this->customerId === '' ? null : Customer::query()->find((int) $this->customerId);
        $this->currency = self::offered((string) $customer?->currency);
    }

    public function updatedCurrency(): void
    {
        $this->currency = self::offered($this->currency);
    }

    /** A currency the quote or order can be written in (SaveSalesDocument::CURRENCIES), else SEK. */
    private static function offered(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        return in_array($currency, SaveSalesDocument::CURRENCIES, true) ? $currency : 'SEK';
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
            'currency' => $this->currency,
            'rows' => $this->rowInput(),
        ], $this->documentId === null ? null : $this->document());

        if ($this->documentId === null) {
            Toast::flash(__(':title är sparad.', ['title' => $document->title()]));
            $this->redirectRoute($this->routeBase().'.show', ['document' => $document->id]);

            return;
        }

        // The saved rows come back with their keys, so a row keeps what hangs on it (ADR-071 §4) at the next save.
        $this->rows = $this->rowsFrom($document->load(['rows.account', 'rows.costCenter', 'rows.project', 'rows.article:id,number'])->rows);
        $this->dispatch('sales-document-saved');
        $this->toast(__(':title är sparad.', ['title' => $document->title()]));
    }

    public function send(SendSalesDocument $send): void
    {
        $send($this->user(), $this->document(), $this->email);

        $this->toast(__(':title är skickad till :email.', ['title' => $this->document()->title(), 'email' => $this->email]));
    }

    /**
     * A quote whose last day has passed asks first which prices apply
     * (ADR-082 §6): $prices 'keep' (the default) or 'today'.
     */
    public function toOrder(string $prices = ''): void
    {
        if ($this->asksForPrices('order', $prices)) {
            return;
        }

        $order = app(ProgressSalesDocument::class)->toOrder($this->user(), $this->document(), todaysPrices: $prices === 'today');

        Toast::flash(__('Offerten är godkänd och blev :title.', ['title' => $order->title()]));
        $this->redirectRoute('invoicing.orders.show', ['document' => $order->id]);
    }

    public function toInvoice(string $prices = ''): void
    {
        if ($this->asksForPrices('invoice', $prices)) {
            return;
        }

        $invoice = app(ProgressSalesDocument::class)->toInvoice($this->user(), $this->document(), todaysPrices: $prices === 'today');

        Toast::flash(__('Ett fakturautkast är skapat från :title. Kontrollera det och skapa fakturan.', ['title' => $this->document()->title()]));
        $this->redirectRoute('invoicing.invoices.edit', ['invoice' => $invoice->id]);
    }

    /** "Hämta dagens priser" for an expired quote: what today's prices would change, before anything changes. */
    public function previewTodaysPrices(): void
    {
        $document = $this->document();
        $context = $this->makePricingContext((string) $document->customer_id, now()->toDateString(), $document->currency);

        $this->todaysDiff = $context === null ? [] : $this->priceChanges($context, $this->rowsFrom($document->load(['rows.account', 'rows.costCenter', 'rows.project', 'rows.article:id,number'])->rows));
    }

    public function closeExpiredChoice(): void
    {
        $this->expiredChoice = null;
        $this->todaysDiff = null;
        $this->dispatch('price-panel-closed');
    }

    /** True when the quote has expired and no choice of prices is made yet: the choice is shown instead. */
    private function asksForPrices(string $target, string $prices): bool
    {
        $document = $this->document();

        if (in_array($prices, ['keep', 'today'], true) || ! $document->isQuote() || $document->until_date === null || ! $document->until_date->lt(today())) {
            return false;
        }

        $this->expiredChoice = $target;
        $this->todaysDiff = null;

        return true;
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
            'currencies' => InvoiceEditor::CURRENCIES,
            ...$this->rowData(),
            'vatRules' => $this->salesVatRules(),
            'routeBase' => $this->routeBase(),
        ]);
    }

    /** Article rows are priced for the customer on the document date in its currency. */
    protected function pricingContext(): ?PriceContext
    {
        return $this->makePricingContext($this->customerId, $this->documentDate, $this->currency);
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
