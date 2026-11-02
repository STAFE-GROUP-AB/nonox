<?php

declare(strict_types=1);

namespace App\Livewire\Payables;

use App\Actions\Payables\ApprovePaymentBatch;
use App\Actions\Payables\CreatePaymentBatch;
use App\Actions\Payables\SavePaymentBankSettings;
use App\Actions\Payables\SettlePaymentBatch;
use App\Domain\Payables\PaymentBatchStatus;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\SupplierInvoice;
use App\Models\SupplierPaymentBatch;
use App\Models\SupplierPaymentBatchItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Payment files: choose the approved invoices to pay, make the file, have it
 * approved before it goes to the bank, and confirm or cancel it when the
 * bank has answered.
 */
class PaymentBatches extends Component
{
    use ShowsToasts;

    /** @var list<string> */
    public array $selected = [];

    public string $executionDate = '';

    /** @var array{payment_bank: string, iban: string, bank_agreement_id: string} */
    public array $bank = ['payment_bank' => '', 'iban' => '', 'bank_agreement_id' => ''];

    public bool $editingBank = false;

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [SupplierPaymentBatch::class, $this->company()]), 403);

        $company = $this->company();
        $this->executionDate = CarbonImmutable::today()->toDateString();
        $this->bank = [
            'payment_bank' => (string) $company->payment_bank?->value,
            'iban' => (string) $company->iban,
            'bank_agreement_id' => (string) $company->bank_agreement_id,
        ];
        $this->editingBank = $company->payment_bank === null;
        $this->selected = array_values(array_map(
            fn (mixed $id): string => (string) $id,
            $this->payable()->where('due_date', '<=', CarbonImmutable::today()->addDays(7)->toDateString())->pluck('id')->all(),
        ));
    }

    public function saveBank(SavePaymentBankSettings $save): void
    {
        $save($this->user(), $this->company(), $this->bank);

        $this->editingBank = false;
        $this->toast(__('Bankuppgifterna för betalfiler är sparade.'));
    }

    public function create(CreatePaymentBatch $create): void
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->executionDate)
            ?: throw ValidationException::withMessages(['execution_date' => __('Välj betaldag.')]);

        $batch = $create($this->user(), $this->company(), $this->selected, $date);

        $this->selected = [];
        $this->toast(__(':title är skapad med :count betalningar. Den behöver godkännas innan den kan laddas ned och läsas in i internetbanken.', ['title' => $batch->title(), 'count' => $batch->payment_count]));
    }

    public function approve(string $batchId, ApprovePaymentBatch $approve): void
    {
        try {
            $batch = $approve($this->user(), SupplierPaymentBatch::query()->findOrFail($batchId));
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(__(':title är godkänd. Ladda ned den och läs in den i internetbanken.', ['title' => $batch->title()]));
    }

    public function confirm(string $batchId, SettlePaymentBatch $settle): void
    {
        try {
            $batch = $settle->confirm($this->user(), SupplierPaymentBatch::query()->findOrFail($batchId));
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(__(':title är markerad som betald. Betalningarna är bokförda.', ['title' => $batch->title()]));
    }

    public function cancel(string $batchId, SettlePaymentBatch $settle): void
    {
        try {
            $batch = $settle->cancel($this->user(), SupplierPaymentBatch::query()->findOrFail($batchId));
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(__(':title är makulerad. Fakturorna kan betalas igen.', ['title' => $batch->title()]));
    }

    /** The bank's answers (PaymentResponses) paid or rejected payments; the lists are read again. */
    #[On('payment-responses-updated')]
    public function refreshAfterBankResponse(): void {}

    public function render(): View
    {
        $payable = $this->payable()->with('supplier:id,name')->orderBy('due_date')->orderBy('number')->get();
        $selectedTotal = $payable->whereIn('id', $this->selected)->sum(fn (SupplierInvoice $invoice): int => $invoice->remaining()->ore);

        return view('livewire.payables.payment-batches', [
            'company' => $this->company(),
            'payable' => $payable,
            'selectedTotal' => $selectedTotal,
            'batches' => SupplierPaymentBatch::query()
                ->with(['creator:id,name', 'approver:id,name'])
                ->withCount([
                    'items as paid_count' => fn (Builder $query) => $query->whereNotNull('supplier_payment_id'),
                    'items as rejected_count' => fn (Builder $query) => $query->where('bank_status', SupplierPaymentBatchItem::REJECTED),
                ])
                ->orderByDesc('number')
                ->limit(50)
                ->get(),
            'canCreate' => $this->user()->can('create', [SupplierPaymentBatch::class, $this->company()]),
            // Checked once for the page rather than through the policy per batch, which would query the company each time.
            'canApprove' => $this->user()->hasTeamPermission($this->company(), 'payments:approve'),
            'separateApproval' => $this->company()->separate_supplier_approval,
            'userId' => $this->user()->id,
            'canChangeBank' => $this->user()->can('update', $this->company()),
        ]);
    }

    /**
     * Approved invoices in kronor that are not already in a file waiting for
     * the bank. One in another currency is paid in the internet bank.
     *
     * @return Builder<SupplierInvoice>
     */
    private function payable(): Builder
    {
        return SupplierInvoice::query()
            ->where('status', SupplierInvoiceStatus::Approved->value)
            ->where('kind', SupplierInvoice::INVOICE)
            ->where('invoice_currency', 'SEK')
            ->whereDoesntHave('batchItems', fn (Builder $query) => $query
                ->where(fn (Builder $item) => $item->whereNull('bank_status')->orWhere('bank_status', '!=', SupplierPaymentBatchItem::REJECTED))
                ->whereHas('batch', fn (Builder $batch) => $batch->where('status', PaymentBatchStatus::Created->value)));
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
