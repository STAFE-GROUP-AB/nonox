<?php

declare(strict_types=1);

namespace App\Livewire\Payables;

use App\Actions\Payables\ApplyPaymentResponses;
use App\Actions\Payables\ImportPaymentResponseFile;
use App\Domain\Payables\PaymentResponseStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\PaymentResponse;
use App\Models\PaymentResponseImport;
use App\Models\SupplierPaymentBatch;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The bank's answers to payment files: read a status report or debit
 * notification, see what was matched automatically, what needs a person,
 * what the bank rejected and what is unknown, and confirm or dismiss.
 */
class PaymentResponses extends Component
{
    use ShowsToasts;
    use WithFileUploads;

    public ?TemporaryUploadedFile $file = null;

    #[Url(as: 'svar', except: '')]
    public string $importId = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [SupplierPaymentBatch::class, $this->company()]), 403);

        if ($this->importId === '') {
            $this->importId = (string) PaymentResponseImport::query()->latest('created_at')->value('id');
        }
    }

    public function updatedFile(ImportPaymentResponseFile $import): void
    {
        $this->validate(['file' => ['required', 'file', 'max:'.ImportPaymentResponseFile::MAX_KILOBYTES]]);

        if (! $this->file instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $result = $import($this->user(), $this->company(), $this->file);
        } finally {
            $this->file = null;
        }

        $this->importId = $result['import']->id;
        $this->dispatch('payment-responses-updated');

        $message = $result['created'] === 0
            ? __('Filen är inläst. Den innehöll inga nya svar om företagets betalfiler.')
            : __('Filen är inläst: :applied klara automatiskt, :open att hantera.', ['applied' => $result['applied'], 'open' => $result['open']]);

        if ($result['skipped'] > 0) {
            $message .= ' '.__(':count svar fanns redan från en tidigare fil och hoppades över.', ['count' => $result['skipped']]);
        }

        $this->toast($message);
    }

    public function confirm(int $responseId, ApplyPaymentResponses $apply): void
    {
        try {
            $response = $apply->confirm($this->user(), PaymentResponse::query()->findOrFail($responseId));
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->dispatch('payment-responses-updated');
        $this->toast($response->status === PaymentResponseStatus::Duplicate
            ? __('Betalningen var redan bokförd. Inget nytt är bokfört.')
            : __('Svaret från banken är bekräftat och bokfört.'));
    }

    public function dismiss(int $responseId, ApplyPaymentResponses $apply): void
    {
        try {
            $apply->dismiss($this->user(), PaymentResponse::query()->findOrFail($responseId));
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->dispatch('payment-responses-updated');
        $this->toast(__('Svaret från banken är avfärdat. Inget är bokfört.'));
    }

    public function render(): View
    {
        $with = ['batch', 'item.invoice.supplier:id,name'];
        $import = $this->importId === '' ? null : PaymentResponseImport::query()->with('creator:id,name')->find($this->importId);

        return view('livewire.payables.payment-responses', [
            'import' => $import,
            'responses' => $import === null ? collect() : PaymentResponse::query()->where('payment_response_import_id', $import->id)->with($with)->orderBy('id')->get(),
            'open' => PaymentResponse::query()
                ->whereIn('status', [PaymentResponseStatus::Suggested->value, PaymentResponseStatus::Unmatched->value])
                ->with($with)
                ->orderBy('date')
                ->orderBy('id')
                ->limit(100)
                ->get(),
            'imports' => PaymentResponseImport::query()->with('creator:id,name')->latest('created_at')->limit(10)->get(),
            'canHandle' => $this->user()->can('create', [SupplierPaymentBatch::class, $this->company()]),
        ]);
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
