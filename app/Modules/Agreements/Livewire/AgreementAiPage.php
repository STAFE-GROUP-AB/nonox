<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Agreements\Actions\AgreementAi;
use App\Modules\Agreements\Models\AgreementDocument;
use App\Modules\Agreements\Models\AgreementProposal;
use App\Modules\Agreements\Support\AgreementVersions;
use App\Modules\Ai\Support\AiAnalysisException;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Lön → Avtal → NONOX AI (ADR-033 §6): upload the company's documents (a
 * hängavtal, a membership confirmation) and the employees' contracts, let
 * NONOX AI read them, and review every proposal: Godkänn, Ändra or Avvisa
 * per row, or Godkänn for every row whose quote was found in the document.
 * Nothing is recorded before a person accepts it.
 */
class AgreementAiPage extends Component
{
    use ShowsToasts;
    use WithFileUploads;

    /** @var ?TemporaryUploadedFile */
    public $document = null;

    public string $documentType = 'HANGAVTAL';

    public string $employeeId = '';

    #[Locked]
    public ?int $editing = null;

    public string $editValue = '';

    /** @var array{agreement_key: string, variant: string, bound_from: string} */
    public array $editBinding = ['agreement_key' => '', 'variant' => '', 'bound_from' => ''];

    public function mount(): void
    {
        abort_unless(AgreementAi::availableFor($this->user(), $this->company()), 404);
    }

    public function updatedEmployeeId(): void
    {
        $this->documentType = $this->employeeId === '' ? 'HANGAVTAL' : 'EMPLOYMENT_CONTRACT';
    }

    public function upload(AgreementAi $ai): void
    {
        $this->validate(['document' => ['required', 'file', 'max:5120']], [], ['document' => __('dokument')]);
        $employee = $this->employeeId === '' ? null : Employee::query()->findOrFail((int) $this->employeeId);
        $file = $this->document;

        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }
        $mime = $file->getMimeType() === 'text/plain' || strtolower($file->getClientOriginalExtension()) === 'txt' ? 'text/plain' : (string) $file->getMimeType();

        $ai->upload($this->user(), $this->company(), $employee, $this->documentType, $file->getClientOriginalName(), $mime, (string) file_get_contents($file->getRealPath()));
        $this->reset('document');
        $this->toast($employee === null ? __('Dokumentet är uppladdat. Låt NONOX AI läsa det.') : __('Dokumentet för :name är uppladdat.', ['name' => "{$employee->first_name} {$employee->last_name}"]));
    }

    public function identify(AgreementAi $ai, int $documentId): void
    {
        try {
            $count = $ai->identify($this->user(), $this->company(), AgreementDocument::query()->findOrFail($documentId));
        } catch (AiAnalysisException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->toast($count === 0 ? __('NONOX AI hittade inget avtal i dokumentet.') : __('NONOX AI har läst dokumentet och föreslår :count uppgifter. Granska dem nedan.', ['count' => $count]));
    }

    public function analyzeAll(AgreementAi $ai): void
    {
        $result = $ai->analyzeAll($this->user(), $this->company());

        foreach ($result['failed'] as $failure) {
            $this->addError('analysis', $failure);
        }

        $this->toast($result['analysed'] === 0 && $result['failed'] === []
            ? __('Det finns inga nya anställningsdokument att läsa. Ladda upp anställningsavtal först.')
            : __('NONOX AI har läst :count dokument och föreslår :proposals uppgifter. Granska dem nedan.', ['count' => $result['analysed'], 'proposals' => $result['proposals']]), $result['failed'] === [] ? 'success' : 'error');
    }

    public function deleteDocument(AgreementAi $ai, int $documentId): void
    {
        $ai->delete($this->user(), $this->company(), AgreementDocument::query()->findOrFail($documentId));
        $this->toast(__('Dokumentet och dess förslag är borttagna.'));
    }

    public function accept(AgreementAi $ai, int $proposalId): void
    {
        $this->decide(fn () => $ai->accept($this->user(), $this->company(), AgreementProposal::query()->findOrFail($proposalId)), __('Förslaget är godkänt och infört.'));
    }

    public function edit(int $proposalId): void
    {
        $proposal = AgreementProposal::query()->findOrFail($proposalId);
        $this->editing = $proposal->id;
        $value = $proposal->value;
        $this->editValue = is_array($value) ? '' : (string) $value;
        $this->editBinding = is_array($value) ? ['agreement_key' => (string) ($value['agreement_key'] ?? ''), 'variant' => (string) ($value['variant'] ?? ''), 'bound_from' => (string) ($value['bound_from'] ?? '')] : $this->editBinding;
        $this->resetErrorBag();
    }

    public function saveEdit(AgreementAi $ai): void
    {
        $proposal = AgreementProposal::query()->findOrFail($this->editing);
        $value = $proposal->field === 'binding' ? $this->editBinding : trim($this->editValue);

        if ($this->decide(fn () => $ai->accept($this->user(), $this->company(), $proposal, $value), __('Uppgiften är ändrad och införd.'))) {
            $this->editing = null;
        }
    }

    public function reject(AgreementAi $ai, int $proposalId): void
    {
        $this->decide(fn () => $ai->reject($this->user(), $this->company(), AgreementProposal::query()->findOrFail($proposalId)), __('Förslaget är avvisat.'));
    }

    public function acceptMatching(AgreementAi $ai, ?int $employeeId = null): void
    {
        $result = $ai->acceptMatching($this->user(), $this->company(), $employeeId);
        $this->toast($result['left'] === 0
            ? __(':count förslag är godkända och införda.', ['count' => $result['accepted']])
            : __(':count förslag är godkända och införda. :left behöver ändras eller avvisas för hand.', ['count' => $result['accepted'], 'left' => $result['left']]));
    }

    public function render(): View
    {
        $documents = AgreementDocument::query()->with('employee')->orderByDesc('id')->get();
        $proposals = AgreementProposal::query()
            ->with(['employee', 'employment.collectiveAgreement', 'document'])
            ->where('status', AgreementProposal::PENDING)
            ->orderBy('employee_id')
            ->orderBy('id')
            ->get();

        return view('agreements::livewire.ai', [
            'companyDocuments' => $documents->whereNull('employee_id')->values(),
            'employeeDocuments' => $documents->whereNotNull('employee_id')->values(),
            'employees' => Employee::query()->where('status', '<>', 'TERMINATED')->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'groups' => $proposals->groupBy(fn (AgreementProposal $proposal): string => (string) $proposal->employee_id),
            'agreements' => AgreementVersions::agreements(),
        ]);
    }

    /** @param  callable(): mixed  $action */
    private function decide(callable $action, string $message): bool
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->addError('proposal', (string) collect($exception->errors())->flatten()->first());

            return false;
        }

        $this->toast($message);

        return true;
    }

    private function company(): Company
    {
        /** @var Company */
        return $this->user()->currentCompany();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
