<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Invoicing\ForeignSale;
use App\Domain\Invoicing\SalesRowInput;
use App\Domain\Vat\OssThreshold;
use App\Livewire\Concerns\ShowsToasts;
use App\Livewire\Invoicing\Concerns\EditsSalesRows;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Currency\ExchangeRate;
use App\Support\Currency\RiksbankRates;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Writing an invoice: customer, dates and rows, with the totals updated as
 * you type. Saving keeps a draft; "Skapa fakturan" issues it.
 */
class InvoiceEditor extends Component
{
    use EditsSalesRows;
    use ShowsToasts;

    #[Locked]
    public ?string $invoiceId = null;

    #[Locked]
    public bool $credit = false;

    public string $customerId = '';

    public string $invoiceDate = '';

    public string $dueDate = '';

    public string $deliveryDate = '';

    public string $yourReference = '';

    public string $ourReference = '';

    public string $notes = '';

    public bool $reverseCharge = false;

    /** VAT charged although the company is not registered for VAT, confirmed (#51). */
    public bool $unregisteredVatConfirmed = false;

    public string $foreignSale = '';

    public string $taxReduction = '';

    public string $propertyDesignation = '';

    /** The hours worked on a ROT or RUT invoice, as typed: "4" or "1,5" (ADR-074 §5). */
    public string $labourHours = '';

    /** The kind of work on a ROT or RUT invoice (ADR-074 §5). */
    public string $workDescription = '';

    /** The currencies offered in the form; Riksbanken publishes rates for all of them. Another code can be typed. */
    public const array CURRENCIES = ['SEK', 'EUR', 'USD', 'GBP', 'NOK', 'DKK', 'CHF'];

    /** ISO 4217. In another currency the prices are written in it and booked in kronor at $exchangeRate. */
    public string $currency = 'SEK';

    /** Kronor per unit, "11,234500"; prefilled with Riksbanken's rate on the delivery date, or the invoice date without one. */
    public string $exchangeRate = '';

    /** The day of Riksbanken's rate, for the hint under the field; empty when the rate was typed. */
    public string $rateDate = '';

    public function mount(?Invoice $invoice = null): void
    {
        if ($invoice instanceof Invoice) {
            abort_unless(Gate::allows('update', $invoice), 403);

            $invoice->load('rows.account');
            $this->invoiceId = $invoice->id;
            $this->credit = $invoice->isCredit();
            $this->customerId = (string) $invoice->customer_id;
            $this->invoiceDate = $invoice->invoice_date->toDateString();
            $this->dueDate = $invoice->due_date->toDateString();
            $this->deliveryDate = (string) $invoice->delivery_date?->toDateString();
            $this->yourReference = (string) $invoice->your_reference;
            $this->ourReference = (string) $invoice->our_reference;
            $this->notes = (string) $invoice->notes;
            $this->reverseCharge = $invoice->reverse_charge;
            $this->unregisteredVatConfirmed = $invoice->unregistered_vat_confirmed;
            $this->foreignSale = (string) $invoice->foreign_sale?->value;
            $this->taxReduction = (string) $invoice->tax_reduction;
            $this->propertyDesignation = (string) $invoice->property_designation;
            $this->labourHours = (string) $invoice->labourHoursLabel();
            $this->workDescription = (string) $invoice->work_description;
            $this->currency = $invoice->invoice_currency;
            $this->exchangeRate = (string) $invoice->bookingRate()?->format();
            $this->rateDate = $invoice->exchange_rate_source === 'riksbank' ? (string) $invoice->exchange_rate_date?->toDateString() : '';
            $this->rows = $this->rowsFrom($invoice->rows);

            return;
        }

        abort_unless(Gate::allows('create', [Invoice::class, $this->company()]), 403);

        $this->invoiceDate = now()->toDateString();
        $this->rows = [$this->blankRow()];

        // ?kund=<id>: a new invoice for that customer, as from the CRM card (ADR-043).
        $customer = request()->integer('kund');

        if ($customer > 0 && Customer::query()->whereKey($customer)->where('is_active', true)->whereNull('processing_restricted_at')->exists()) {
            $this->customerId = (string) $customer;
            $this->updatedCustomerId();
        }
    }

    /** A new choice of customer brings that customer's VAT treatment. */
    public function updatedCustomerId(): void
    {
        $customer = $this->customerId === '' ? null : Customer::query()->find((int) $this->customerId);
        $this->reverseCharge = $customer->reverse_charge_construction ?? false;
        $this->foreignSale = (string) $customer?->foreign_sale?->value;
        $this->currency = $customer->currency ?? 'SEK';
        $this->fetchRate();
    }

    /** A new currency, invoice date or delivery date: Riksbanken's rate for the day the VAT arises. */
    public function updatedCurrency(): void
    {
        $this->currency = strtoupper(trim($this->currency)) ?: 'SEK';
        $this->fetchRate();
    }

    public function updatedInvoiceDate(): void
    {
        $this->fetchRate();
    }

    public function updatedDeliveryDate(): void
    {
        $this->fetchRate();
    }

    public function updatedExchangeRate(): void
    {
        $this->rateDate = '';
    }

    private function fetchRate(): void
    {
        if ($this->credit || $this->currency === 'SEK' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->invoiceDate) !== 1) {
            $this->exchangeRate = $this->currency === 'SEK' ? '' : $this->exchangeRate;

            return;
        }

        $rate = app(RiksbankRates::class)->rate($this->currency, SaveDraftInvoice::vatDate(['delivery_date' => $this->deliveryDate, 'invoice_date' => $this->invoiceDate]));
        $this->exchangeRate = (string) $rate?->format();
        $this->rateDate = (string) $rate?->date;
    }

    public function save(SaveDraftInvoice $save): void
    {
        $invoice = $this->persist($save);

        if ($this->invoiceId === null) {
            Toast::flash(__('Utkastet är sparat.'));
            $this->redirectRoute('invoicing.invoices.edit', ['invoice' => $invoice->id]);

            return;
        }

        $this->toast(__('Utkastet är sparat.'));
    }

    public function issue(SaveDraftInvoice $save, IssueInvoice $issue): void
    {
        $invoice = $issue($this->user(), $this->persist($save));

        Toast::flash(__(':title är skapad och bokförd. Skicka den till kunden här.', ['title' => $invoice->title()]));
        $this->redirectRoute('invoicing.invoices.show', ['invoice' => $invoice->id]);
    }

    public function render(): View
    {
        return view('livewire.invoicing.invoice-editor', [
            'customers' => Customer::query()->where('is_active', true)->whereNull('processing_restricted_at')->orderBy('name')->get(['id', 'number', 'name']),
            'currencies' => self::CURRENCIES,
            'articles' => $this->rowArticles(),
            'vatRules' => $this->salesVatRules(),
            'ossThreshold' => $this->ossThreshold(),
            'vatUnregistered' => ! $this->company()->isVatRegistered(),
            'vatExemption' => $this->company()->vat_exemption,
            'canIssue' => $this->invoiceId === null
                ? $this->user()->hasTeamPermission($this->company(), 'invoices:send')
                : Gate::allows('issue', Invoice::query()->findOrFail($this->invoiceId)),
        ]);
    }

    /**
     * Where the company stands against the OSS threshold with this invoice,
     * for a sale to a consumer in another EU country; null otherwise.
     *
     * @return ?array{elected: bool, previous: int, current: int, after: int, swedish_vat: bool, crosses: bool, near: bool}
     */
    private function ossThreshold(): ?array
    {
        if ($this->credit || $this->foreignSale !== ForeignSale::EuConsumerOss->value) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->invoiceDate);

        if (! $date instanceof CarbonImmutable) {
            return null;
        }

        try {
            $rows = SalesRowInput::parse($this->rowInput());
        } catch (ValidationException) {
            $rows = [];
        }

        $rate = $this->currency === 'SEK' ? null : ExchangeRate::parse($this->currency, $this->exchangeRate);
        $net = array_sum(array_map(fn (array $row): int => $rate?->toOre((int) $row['net_ore']) ?? ($this->currency === 'SEK' ? (int) $row['net_ore'] : 0), $rows));

        return OssThreshold::check($this->company(), $date, $net, $this->invoiceId);
    }

    private function persist(SaveDraftInvoice $save): Invoice
    {
        $this->resetErrorBag();

        return $save($this->user(), $this->company(), [
            'customer_id' => $this->customerId === '' ? null : (int) $this->customerId,
            'invoice_date' => $this->invoiceDate,
            'due_date' => $this->dueDate === '' ? null : $this->dueDate,
            'delivery_date' => $this->deliveryDate === '' ? null : $this->deliveryDate,
            'your_reference' => $this->yourReference,
            'our_reference' => $this->ourReference,
            'notes' => $this->notes,
            'reverse_charge' => $this->reverseCharge,
            'unregistered_vat_confirmed' => $this->unregisteredVatConfirmed,
            'foreign_sale' => $this->foreignSale === '' ? null : $this->foreignSale,
            'tax_reduction' => $this->taxReduction === '' ? null : $this->taxReduction,
            'property_designation' => $this->propertyDesignation,
            'labour_hours' => $this->labourHours,
            'work_description' => $this->workDescription,
            'currency' => $this->currency,
            'exchange_rate' => $this->currency === 'SEK' ? null : $this->exchangeRate,
            'rows' => $this->rowInput(),
        ], $this->invoiceId === null ? null : Invoice::query()->findOrFail($this->invoiceId));
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
