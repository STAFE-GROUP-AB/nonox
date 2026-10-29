<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Invoicing\InvoiceCalculator;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Article;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Writing an invoice: customer, dates and rows, with the totals updated as
 * you type. Saving keeps a draft; "Skapa fakturan" issues it.
 */
class InvoiceEditor extends Component
{
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

    /** @var array<int, array<string, string>> */
    public array $rows = [];

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
            $this->rows = $invoice->rows->map(fn (InvoiceRow $row): array => [
                'article' => '',
                'description' => $row->description,
                'quantity' => str_replace('.', ',', rtrim(rtrim(ltrim($row->quantity, '-'), '0'), '.')),
                'unit' => $row->unit,
                'price' => $row->unit_price_ore->format(withCurrency: false),
                'discount' => $row->discount_bp === 0 ? '' : str_replace('.', ',', (string) ($row->discount_bp / 100)),
                'vat_rate' => (string) $row->vat_rate,
                'account' => (string) $row->account?->number,
            ])->values()->all();

            return;
        }

        abort_unless(Gate::allows('create', [Invoice::class, $this->company()]), 403);

        $this->invoiceDate = now()->toDateString();
        $this->rows = [$this->blankRow()];
    }

    public function addRow(): void
    {
        $this->rows[] = $this->blankRow();
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);

        if ($this->rows === []) {
            $this->rows = [$this->blankRow()];
        }
    }

    /**
     * Filling in an article number fills the row from the article.
     */
    public function updatedRows(mixed $value, string $key): void
    {
        if (! preg_match('/^(\d+)\.article$/', $key, $match)) {
            return;
        }

        $article = Article::query()->where('number', strtok(trim((string) $value), ' '))->first();

        if (! $article instanceof Article) {
            return;
        }

        $index = (int) $match[1];
        $this->rows[$index] = [
            ...$this->rows[$index],
            'article' => $article->number,
            'description' => $article->name,
            'unit' => $article->unit,
            'price' => $article->price_ore->format(withCurrency: false),
            'vat_rate' => (string) $article->vat_rate,
            'quantity' => ($this->rows[$index]['quantity'] ?? '') !== '' ? $this->rows[$index]['quantity'] : '1',
            'account' => '',
        ];
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
            'articles' => Article::query()->where('is_active', true)->orderBy('number')->get(['number', 'name']),
            'totals' => $this->previewTotals(),
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
            'rows' => array_map(fn (array $row): array => [...$row, 'vat_rate' => (int) ($row['vat_rate'] ?? 2500), 'account' => ($row['account'] ?? '') === '' ? null : (int) $row['account']], $this->filledRows()),
        ], $this->invoiceId === null ? null : Invoice::query()->findOrFail($this->invoiceId));
    }

    /**
     * Totals of the rows that can be read, while typing.
     *
     * @return array{net: int, vat: int, rounding: int, total: int, vat_by_rate: array<int, array{base: int, vat: int}>}
     */
    private function previewTotals(): array
    {
        $rows = [];

        foreach ($this->filledRows() as $row) {
            $quantity = InvoiceCalculator::quantity((string) ($row['quantity'] ?? ''));

            try {
                $price = Money::parse((string) ($row['price'] ?? ''));
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($quantity === null) {
                continue;
            }

            $discount = InvoiceCalculator::discount((string) ($row['discount'] ?? '')) ?? 0;
            $net = InvoiceCalculator::rowNet($price, $this->credit ? "-{$quantity}" : $quantity, $discount)->ore;
            $rows[] = ['net' => $net, 'vat_rate' => (int) ($row['vat_rate'] ?? 2500)];
        }

        return InvoiceCalculator::totals($rows);
    }

    /**
     * @return list<array<string, string>>
     */
    private function filledRows(): array
    {
        return array_values(array_filter($this->rows, fn (array $row): bool => trim((string) ($row['description'] ?? '')) !== '' || trim((string) ($row['price'] ?? '')) !== ''));
    }

    /** @return array<string, string> */
    private function blankRow(): array
    {
        return ['article' => '', 'description' => '', 'quantity' => '1', 'unit' => 'st', 'price' => '', 'discount' => '', 'vat_rate' => '2500', 'account' => ''];
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
