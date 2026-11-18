<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Budget\ImportSieBudget;
use App\Domain\Budget\Sie\SieBudgetAnalyser;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\BudgetVersion;
use App\Models\Company;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Reads the budget of a SIE file into a new budget version, in two steps
 * (ADR-081 E4): "Granska filen" shows what the file holds and changes
 * nothing; "Läs in budgeten" checks the file again and writes the version.
 * The bookkeeping in the file is not read in.
 */
class BudgetSieImport extends Component
{
    use ShowsToasts;
    use WithFileUploads;

    /** Kilobytes. A budget file is small; a whole SIE4 file goes through Läs in SIE-fil. */
    public const int MAX_KILOBYTES = 12_288;

    public ?TemporaryUploadedFile $file = null;

    /** @var array<string, mixed>|null the report of the file under review; the import checks the file again */
    #[Locked]
    public ?array $report = null;

    public function updatedFile(): void
    {
        $this->report = null;
    }

    public function analyse(SieBudgetAnalyser $analyser): void
    {
        $this->authorize('create', [BudgetVersion::class, $this->company()]);
        $this->validate(['file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES]], [
            'file.required' => __('Välj en SIE-fil att läsa in.'),
            'file.file' => __('Välj en SIE-fil att läsa in.'),
            'file.max' => __('Filen är för stor. Den får vara högst 12 MB.'),
        ]);

        $this->report = $analyser->analyse($this->company(), $this->path())->toArray();

        if ($this->report['blockers'] !== []) {
            $this->toast(__('Filen är granskad, men budgeten kan inte läsas in som den är. Se vad som behöver rättas.'), 'error');

            return;
        }

        $this->toast(__('Filen är granskad. Läs igenom rapporten och läs sedan in budgeten.'));
    }

    public function import(ImportSieBudget $import): void
    {
        if ($this->report === null || $this->file === null || $this->report['blockers'] !== []) {
            return;
        }

        $sha256 = (string) hash_file('sha256', $this->path());

        // A double click: the second request waits for nothing and does nothing; the first one resets the report.
        $lock = Cache::lock("budget-sie-import:{$this->user()->id}:{$sha256}", 60);

        if (! $lock->get()) {
            return;
        }

        try {
            $version = $import($this->user(), $this->company(), $this->path(), $sha256);
        } finally {
            $lock->release();
        }

        $this->file->delete();
        $this->reset('file', 'report');

        Toast::flash(__('Budgeten är inläst som en ny budget med :count rader.', ['count' => $version->lines()->count()]));
        $this->redirectRoute('accounting.budgets.show', ['budgetVersion' => $version->id], navigate: true);
    }

    public function cancel(): void
    {
        $this->file?->delete();
        $this->reset('file', 'report');
    }

    public function render(): View
    {
        return view('livewire.accounting.budget-sie-import', [
            'canManage' => $this->user()->can('create', [BudgetVersion::class, $this->company()]),
        ]);
    }

    private function path(): string
    {
        return (string) $this->file?->getRealPath();
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
