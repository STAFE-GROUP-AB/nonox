<?php

declare(strict_types=1);

namespace App\Livewire\Payables;

use App\Actions\Payables\DeleteSupplierInvoiceDraft;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceRow;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Registering a supplier invoice: the invoice file beside the form, the
 * amounts as printed, and the cost rows. Saving keeps a draft; "Registrera"
 * books it and sends it for approval.
 */
class SupplierInvoiceEditor extends Component
{
    use ShowsToasts;
    use WithFileUploads;

    #[Locked]
    public ?string $invoiceId = null;

    public string $supplierId = '';

    public string $invoiceNumber = '';

    public string $ocr = '';

    public string $invoiceDate = '';

    public string $dueDate = '';

    public string $description = '';

    public string $total = '';

    public string $vat = '';

    /** @var list<array{account: string, amount: string, description: string, cost_center: string, project: string}> */
    public array $rows = [];

    public ?TemporaryUploadedFile $file = null;

    public function mount(?SupplierInvoice $invoice = null): void
    {
        if ($invoice instanceof SupplierInvoice) {
            abort_unless(Gate::allows('update', $invoice), 403);

            $invoice->load('rows.account', 'rows.costCenter', 'rows.project');
            $this->invoiceId = $invoice->id;
            $this->supplierId = (string) $invoice->supplier_id;
            $this->invoiceNumber = (string) $invoice->invoice_number;
            $this->ocr = (string) $invoice->ocr;
            $this->invoiceDate = $invoice->invoice_date->toDateString();
            $this->dueDate = $invoice->due_date->toDateString();
            $this->description = (string) $invoice->description;
            $this->total = $invoice->total_ore->format(withCurrency: false);
            $this->vat = $invoice->vat_ore->format(withCurrency: false);
            $this->rows = array_values($invoice->rows->map(fn (SupplierInvoiceRow $row): array => [
                'account' => (string) $row->account?->number,
                'amount' => $row->amount_ore->format(withCurrency: false),
                'description' => (string) $row->description,
                'cost_center' => (string) $row->costCenter?->code,
                'project' => (string) $row->project?->code,
            ])->all());

            return;
        }

        abort_unless(Gate::allows('create', [SupplierInvoice::class, $this->company()]), 403);

        $this->invoiceDate = now()->toDateString();
    }

    public function save(SaveSupplierInvoice $save): void
    {
        $invoice = $this->persist($save);

        if ($this->invoiceId === null) {
            Toast::flash(__('Utkastet är sparat.'));
            $this->redirectRoute('payables.invoices.edit', ['supplierInvoice' => $invoice->id]);

            return;
        }

        $this->file = null;
        $this->toast(__('Utkastet är sparat.'));
    }

    public function register(SaveSupplierInvoice $save, RegisterSupplierInvoice $register): void
    {
        $invoice = $register($this->user(), $this->persist($save));

        Toast::flash($invoice->voucher_id === null
            ? __(':title är registrerad och väntar på attest. Den bokförs när den betalas (kontantmetoden).', ['title' => $invoice->title()])
            : __(':title är registrerad och bokförd. Den väntar nu på attest.', ['title' => $invoice->title()]));
        $this->redirectRoute('payables.invoices.show', ['supplierInvoice' => $invoice->id]);
    }

    public function delete(DeleteSupplierInvoiceDraft $delete): void
    {
        $delete($this->user(), SupplierInvoice::query()->findOrFail($this->invoiceId));

        Toast::flash(__('Utkastet är borttaget.'));
        $this->redirectRoute('payables.invoices.index');
    }

    public function render(): View
    {
        $invoice = $this->invoiceId === null ? null : SupplierInvoice::query()->findOrFail($this->invoiceId);

        return view('livewire.payables.supplier-invoice-editor', [
            'invoice' => $invoice,
            'suppliers' => Supplier::query()->where('is_active', true)->with('defaultAccount:id,number,name')->orderBy('name')->get(['id', 'number', 'name', 'payment_terms_days', 'default_account_id']),
            'accounts' => Account::query()->where('is_active', true)->whereBetween('number', [1000, 8999])->orderBy('number')->get(['number', 'name']),
            'costCenters' => CostCenter::query()->where('is_active', true)->orderBy('code')->get(['code', 'name']),
            'projects' => Project::query()->where('is_active', true)->orderBy('code')->get(['code', 'name']),
            'canRegister' => $invoice === null
                ? $this->user()->can('create', [SupplierInvoice::class, $this->company()])
                : $this->user()->can('register', $invoice),
        ]);
    }

    private function persist(SaveSupplierInvoice $save): SupplierInvoice
    {
        $this->resetErrorBag();
        $this->validate(['file' => ['nullable', 'file', 'max:10240']], ['file.max' => __('Filen är för stor. Den får vara högst 10 MB.')]);

        return $save($this->user(), $this->company(), [
            'supplier_id' => $this->supplierId === '' ? null : (int) $this->supplierId,
            'invoice_number' => $this->invoiceNumber,
            'ocr' => preg_replace('/\s+/', '', $this->ocr),
            'invoice_date' => $this->invoiceDate,
            'due_date' => $this->dueDate === '' ? null : $this->dueDate,
            'description' => $this->description,
            'total' => $this->total,
            'vat' => $this->vat,
            'rows' => $this->rows,
        ], $this->invoiceId === null ? null : SupplierInvoice::query()->findOrFail($this->invoiceId), $this->file);
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
