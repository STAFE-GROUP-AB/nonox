<?php

declare(strict_types=1);

namespace App\Livewire\Payables;

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Payables\DeleteSupplierInvoiceDraft;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
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
use App\Modules\Ai\Support\AiAnalysisException;
use App\Support\Files\PrivateFile;
use App\Support\Money\Money;
use App\Support\Toast;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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

    /** Session key for a document handed over from the voucher page by NONOX AI. */
    public const string HANDOFF = 'ai.supplier_invoice_handoff';

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

    /** @var ?array{notes: string, confidence: ?float, supplier: ?string, new_supplier: ?array<string, mixed>} what NONOX AI filled in, for the person to check */
    public ?array $aiResult = null;

    /** A document handed over from bookkeeping waits to be analysed until the page is shown. */
    public bool $analyzeOnLoad = false;

    /** Create the supplier NONOX AI read from the invoice when it is not in the register. */
    public bool $createSupplier = true;

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
        $this->takeHandoff();
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
     * A document the voucher page recognised as a supplier invoice: it becomes
     * this invoice's file and is read again, as an invoice, by NONOX AI once
     * the page is shown. Only the same person in the same company can take it
     * over.
     */
    private function takeHandoff(): void
    {
        $handoff = session()->pull(self::HANDOFF);

        if (! is_array($handoff) || ($handoff['user'] ?? null) !== $this->user()->id || ($handoff['company'] ?? null) !== $this->company()->id) {
            return;
        }

        $file = TemporaryUploadedFile::createFromLivewire((string) $handoff['file']);

        if (! $file->exists()) {
            return;
        }

        $this->file = $file;
        $this->analyzeOnLoad = true;
    }

    /** Analyses the handed-over document once the page is on screen, with the countdown running. */
    public function analyzeHandoff(AnalyzeDocument $analyze): void
    {
        if (! $this->analyzeOnLoad) {
            return;
        }

        $this->analyzeOnLoad = false;
        $this->analyze($analyze);
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
            $invoice instanceof SupplierInvoice && $invoice->file_path !== null => [PrivateFile::temporaryCopy(AttachToVoucher::DISK, $invoice->file_path), (string) $invoice->file_mime],
            default => [null, null],
        };

        if ($path === null) {
            $this->toast(__('Dra först in fakturan som PDF eller bild.'), 'error');

            return;
        }

        try {
            $answer = $analyze->supplierInvoice($this->user(), $this->company(), $path, (string) $mime);
        } catch (AiAnalysisException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $amount = fn (mixed $value): string => Money::parse((string) BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp))->format(withCurrency: false);

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
            'confidence' => null,
            'supplier' => null,
            'new_supplier' => $answer['supplier_id'] === null && filled($answer['supplier_name'] ?? null) ? $this->newSupplier($answer) : null,
        ];
        $this->createSupplier = true;
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

    /**
     * The supplier as NONOX AI read it, for the register.
     *
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>
     */
    private function newSupplier(array $answer): array
    {
        $days = filled($answer['invoice_date'] ?? null) && filled($answer['due_date'] ?? null)
            ? (int) CarbonImmutable::parse((string) $answer['invoice_date'])->diffInDays(CarbonImmutable::parse((string) $answer['due_date']))
            : 30;

        return array_filter([
            'name' => (string) $answer['supplier_name'],
            'org_number' => (string) ($answer['supplier_org_number'] ?? ''),
            'vat_number' => strtoupper((string) preg_replace('/\s/', '', (string) ($answer['supplier_vat_number'] ?? ''))),
            'bankgiro' => (string) ($answer['supplier_bankgiro'] ?? ''),
            'plusgiro' => (string) ($answer['supplier_plusgiro'] ?? ''),
            'iban' => (string) ($answer['supplier_iban'] ?? ''),
            'address_line1' => (string) ($answer['supplier_address'] ?? ''),
            'postal_code' => (string) ($answer['supplier_postal_code'] ?? ''),
            'city' => (string) ($answer['supplier_city'] ?? ''),
            'email' => (string) ($answer['supplier_email'] ?? ''),
            'phone' => (string) ($answer['supplier_phone'] ?? ''),
            'payment_terms_days' => max(0, min(365, $days)),
            'default_account' => isset($answer['rows'][0]['account']) ? (int) $answer['rows'][0]['account'] : null,
        ], fn (mixed $value): bool => $value !== '' && $value !== null);
    }

    /**
     * Creates the supplier NONOX AI read, when the person has ticked it and
     * chosen no other, and selects it for the invoice.
     */
    private function createSupplierFromAi(): void
    {
        $new = $this->aiResult['new_supplier'] ?? null;

        if ($this->supplierId !== '' || ! $this->createSupplier || ! is_array($new)) {
            return;
        }

        try {
            $supplier = app(SaveSupplier::class)($this->user(), $this->company(), $new);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['supplier_id' => __('Leverantören kunde inte skapas: :reason Lägg till den under Leverantörer.', ['reason' => (string) collect($exception->errors())->flatten()->first()])]);
        }

        $this->supplierId = (string) $supplier->id;
        $this->aiResult['new_supplier'] = null;
    }

    private function persist(SaveSupplierInvoice $save): SupplierInvoice
    {
        $this->resetErrorBag();
        $this->createSupplierFromAi();
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
