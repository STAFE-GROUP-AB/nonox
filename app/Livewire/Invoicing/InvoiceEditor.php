<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Livewire\Concerns\ShowsToasts;
use App\Livewire\Invoicing\Concerns\EditsSalesRows;
use App\Models\Article;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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

    public string $taxReduction = '';

    public string $propertyDesignation = '';

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
            $this->taxReduction = (string) $invoice->tax_reduction;
            $this->propertyDesignation = (string) $invoice->property_designation;
            $this->rows = $this->rowsFrom($invoice->rows);

            return;
        }

        abort_unless(Gate::allows('create', [Invoice::class, $this->company()]), 403);

        $this->invoiceDate = now()->toDateString();
        $this->rows = [$this->blankRow()];
    }

    /** A new choice of customer brings that customer's VAT treatment. */
    public function updatedCustomerId(): void
    {
        $customer = $this->customerId === '' ? null : Customer::query()->find((int) $this->customerId);
        $this->reverseCharge = $customer->reverse_charge_construction ?? false;
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
            'customers' => Customer::query()->where('is_active', true)->orderBy('name')->get(['id', 'number', 'name']),
            'articles' => Article::query()->where('is_active', true)->orderBy('number')->get(['number', 'name', 'unit', 'price_ore', 'vat_rate']),
            'canIssue' => $this->invoiceId === null
                ? $this->user()->hasTeamPermission($this->company(), 'invoices:send')
                : Gate::allows('issue', Invoice::query()->findOrFail($this->invoiceId)),
        ]);
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
            'tax_reduction' => $this->taxReduction === '' ? null : $this->taxReduction,
            'property_designation' => $this->propertyDesignation,
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
