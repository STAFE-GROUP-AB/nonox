<?php

declare(strict_types=1);

namespace App\Livewire\Payables;

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Payables\DeleteSupplierInvoiceDraft;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Domain\Payables\VatTreatment;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceRow;
use App\Models\User;
use App\Modules\Ai\Actions\AnalyzeDocument;
use App\Modules\Ai\Support\GozeroCore\GozeroCoreException;
use App\Support\Money\Money;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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

    public bool $credit = false;

    public string $vatTreatment = 'DOMESTIC';

    /** @var list<array{account: string, amount: string, description: string, cost_center: string, project: string, vat_rate: string}> */
    public array $rows = [];

    public ?TemporaryUploadedFile $file = null;

    /** @var ?array{notes: string, confidence: ?float, supplier: ?string} what NONOX AI filled in, for the person to check */
    public ?array $aiResult = null;

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
            // A credit note is stored negative and shown as the positive amounts printed on it.
            $sign = $invoice->isCredit() ? -1 : 1;
            $this->credit = $invoice->isCredit();
            $this->vatTreatment = $invoice->vat_treatment->value;
            $this->total = Money::ofOre($invoice->total_ore->ore * $sign)->format(withCurrency: false);
            $this->vat = Money::ofOre($invoice->vat_ore->ore * $sign)->format(withCurrency: false);
            $this->rows = array_values($invoice->rows->map(fn (SupplierInvoiceRow $row): array => [
                'account' => (string) $row->account?->number,
                'amount' => Money::ofOre($row->amount_ore->ore * $sign)->format(withCurrency: false),
                'vat_rate' => (string) ($row->vat_rate ?? 2500),
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
            ? __(':title är registrerad och väntar på attest. Den bokförs när den regleras (kontantmetoden).', ['title' => $invoice->title()])
            : __(':title är registrerad och bokförd. Den väntar nu på attest.', ['title' => $invoice->title()]));
        $this->redirectRoute('payables.invoices.show', ['supplierInvoice' => $invoice->id]);
    }

    /**
     * Lets NONOX AI read the invoice and fill in the form. Nothing is saved:
     * the person checks every field and saves as usual.
     */
    public function analyze(AnalyzeDocument $analyze): void
    {
        $invoice = $this->invoiceId === null ? null : SupplierInvoice::query()->findOrFail($this->invoiceId);
        [$path, $mime] = match (true) {
            $this->file !== null => [(string) $this->file->getRealPath(), (string) $this->file->getMimeType()],
            $invoice instanceof SupplierInvoice && $invoice->file_path !== null => [Storage::disk(AttachToVoucher::DISK)->path($invoice->file_path), (string) $invoice->file_mime],
            default => [null, null],
        };

        if ($path === null) {
            $this->toast(__('Dra först in fakturan som PDF eller bild.'), 'error');

            return;
        }

        try {
            $answer = $analyze->supplierInvoice($this->user(), $this->company(), $path, (string) $mime);
        } catch (GozeroCoreException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $amount = fn (mixed $value): string => Money::parse((string) $value)->format(withCurrency: false);

        $this->supplierId = $answer['supplier_id'] === null ? $this->supplierId : (string) $answer['supplier_id'];
        $this->invoiceNumber = (string) ($answer['invoice_number'] ?? $this->invoiceNumber);
        $this->ocr = (string) ($answer['ocr'] ?? '');
        $this->invoiceDate = (string) ($answer['invoice_date'] ?: $this->invoiceDate);
        $this->dueDate = (string) ($answer['due_date'] ?? '');
        $this->description = (string) ($answer['description'] ?? '');
        $this->credit = (bool) ($answer['is_credit_note'] ?? false);
        $this->vatTreatment = (string) ($answer['vat_treatment'] ?? 'DOMESTIC');
        $this->total = $amount($answer['total'] ?? 0);
        $this->vat = $amount($answer['vat_total'] ?? 0);
        $this->rows = array_values(array_map(fn (mixed $row): array => [
            'account' => (string) ((array) $row)['account'],
            'amount' => $amount(((array) $row)['amount_excl_vat'] ?? 0),
            'vat_rate' => (string) (((int) (((array) $row)['vat_rate_percent'] ?? 25)) * 100),
            'description' => (string) (((array) $row)['description'] ?? ''),
            'cost_center' => '',
            'project' => '',
        ], (array) $answer['rows']));

        $this->aiResult = [
            'notes' => (string) ($answer['notes'] ?? ''),
            'confidence' => $answer['confidence'] ?? null,
            'supplier' => $answer['supplier_id'] === null
                ? trim(implode(', ', array_filter([(string) ($answer['supplier_name'] ?? ''), (string) ($answer['supplier_org_number'] ?? ''), filled($answer['supplier_bankgiro'] ?? null) ? 'bankgiro '.$answer['supplier_bankgiro'] : null])))
                : null,
        ];
        $this->toast(__('NONOX AI har fyllt i fakturan. Kontrollera varje uppgift innan du sparar.'));
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
            'treatments' => VatTreatment::cases(),
            'aiAvailable' => AnalyzeDocument::availableFor($this->user(), $this->company()),
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
            'credit' => $this->credit,
            'vat_treatment' => $this->vatTreatment,
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
