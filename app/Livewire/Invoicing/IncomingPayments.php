<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Actions\Invoicing\BookIncomingPayments;
use App\Actions\Invoicing\ImportBankFile;
use App\Domain\Invoicing\InvoiceStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\BankFileImport;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Incoming payments from the bank's file: read the file, check the suggested
 * invoice for each payment, and book the ones that are right.
 */
class IncomingPayments extends Component
{
    use ShowsToasts;
    use WithFileUploads;

    public ?TemporaryUploadedFile $file = null;

    #[Url(as: 'fil', except: '')]
    public string $importId = '';

    /** @var list<int> */
    public array $selected = [];

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [BankFileImport::class, $this->company()]), 403);

        if ($this->importId === '') {
            $this->importId = (string) BankFileImport::query()->latest('created_at')->value('id');
        }

        $this->selectMatched();
    }

    public function updatedFile(): void
    {
        $this->validate(['file' => ['required', 'file', 'max:'.ImportBankFile::MAX_KILOBYTES]]);

        if (! $this->file instanceof TemporaryUploadedFile) {
            return;
        }

        ['import' => $result, 'skipped' => $skipped] = app(ImportBankFile::class)($this->user(), $this->company(), $this->file);

        $this->file = null;
        $this->importId = $result->id;
        $this->selectMatched();

        $this->toast($skipped === 0
            ? __(':count inbetalningar är inlästa. Kontrollera förslagen och bokför.', ['count' => $result->credit_count])
            : __(':count nya inbetalningar är inlästa. :skipped fanns redan från en tidigare fil och hoppades över.', ['count' => $result->credit_count, 'skipped' => $skipped]));
    }

    public function assign(int $transactionId, string $invoiceId, BookIncomingPayments $book): void
    {
        $book->assign($this->user(), $this->company(), BankTransaction::query()->findOrFail($transactionId), $invoiceId === '' ? null : $invoiceId);

        $this->toast(__('Kopplingen är ändrad.'));
    }

    public function book(BookIncomingPayments $book): void
    {
        $count = $book($this->user(), $this->company(), $this->selected);

        $this->selected = [];
        $this->toast($count === 1 ? __('1 inbetalning är bokförd.') : __(':count inbetalningar är bokförda.', ['count' => $count]));
    }

    public function render(): View
    {
        $import = $this->importId === '' ? null : BankFileImport::query()->find($this->importId);

        return view('livewire.invoicing.incoming-payments', [
            'import' => $import,
            'transactions' => $import?->transactions()->with(['invoice:id,number,ocr,total_ore,paid_ore,customer_snapshot,status,kind,due_date', 'payment.voucher.series'])->get() ?? collect(),
            'imports' => BankFileImport::query()->with('creator:id,name')->latest('created_at')->limit(10)->get(),
            'openInvoices' => Invoice::query()->where('status', InvoiceStatus::Issued->value)->where('kind', Invoice::INVOICE)->orderBy('number')->get(['id', 'number', 'total_ore', 'paid_ore', 'customer_snapshot']),
            'canBook' => $this->user()->can('create', [BankFileImport::class, $this->company()]),
        ]);
    }

    private function selectMatched(): void
    {
        $this->selected = $this->importId === ''
            ? []
            : array_values(array_map('intval', BankTransaction::query()
                ->where('bank_file_import_id', $this->importId)
                ->whereNotNull('invoice_id')
                ->whereNull('invoice_payment_id')
                ->pluck('id')
                ->all()));
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
