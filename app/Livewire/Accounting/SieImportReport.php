<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Accounting\ConfirmSieImport;
use App\Actions\Accounting\ImportSie;
use App\Actions\Accounting\MoveSieImportToCompany;
use App\Domain\Accounting\SieImport\SieImportStatus;
use App\Domain\Company\OrganisationNumber;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\SieImport;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The validation report of an uploaded SIE file, the button that reads it
 * in, its progress, and afterwards the reconciliation against the file.
 */
class SieImportReport extends Component
{
    use ShowsToasts;

    #[Locked]
    public int $importId;

    public function mount(SieImport $sieImport): void
    {
        $this->importId = $sieImport->id;
    }

    public function confirm(ConfirmSieImport $confirmSieImport): void
    {
        $confirmSieImport($this->user(), $this->import());

        $import = $this->import();

        match ($import->status) {
            SieImportStatus::Completed => $this->toast(__('Filen är inläst: :count verifikationer, och allt stämmer med filen.', ['count' => $import->result['vouchers_imported'] ?? 0])),
            SieImportStatus::Failed => $this->toast(__('Filen kunde inte läsas in. Ingenting har ändrats.'), 'error'),
            default => $this->toast(__('Inläsningen har startat. Du kan lämna sidan under tiden.')),
        };
    }

    /** Analyses the same file in the user's company that has the file's organisation number. */
    public function moveTo(int $companyId, MoveSieImportToCompany $move): void
    {
        $target = collect(MoveSieImportToCompany::candidates($this->user(), $this->import()))->firstWhere('id', $companyId);

        if (! $target instanceof Company) {
            return;
        }

        $import = $move($this->user(), $this->import(), $target);

        Toast::flash(__('Du arbetar nu i :company. Filen är granskad där; läs igenom rapporten och läs in den.', ['company' => $target->name]));
        $this->redirectRoute('accounting.sieImports.show', $import);
    }

    /** Opens the new-company form filled in with the file's name and organisation number. */
    public function createCompanyFromFile(): void
    {
        $report = $this->import()->report;

        session()->put('company.prefill', [
            'name' => (string) ($report['company_name'] ?? ''),
            'org_number' => (string) ($report['organisation_number'] ?? ''),
        ]);

        $this->redirectRoute('teams.create');
    }

    public function render(): View
    {
        $import = $this->import();

        $this->authorize('view', $import);

        return view('livewire.accounting.sie-import-report', [
            'import' => $import,
            'report' => $import->report,
            'result' => $import->result,
            'progress' => $import->status->isInProgress() ? Cache::get(ImportSie::progressKey($import)) : null,
            'canConfirm' => $this->user()->can('confirm', $import),
            'otherCompanies' => MoveSieImportToCompany::candidates($this->user(), $import),
            'wrongCompany' => $this->wrongCompany($import),
        ]);
    }

    /** Whether the file names another organisation number than the company it was uploaded in. */
    private function wrongCompany(SieImport $import): bool
    {
        $theirs = OrganisationNumber::tryFrom((string) ($import->report['organisation_number'] ?? ''));
        $ours = OrganisationNumber::tryFrom((string) Company::query()->whereKey($import->company_id)->value('org_number'));

        return $theirs !== null && $theirs->digits !== $ours?->digits;
    }

    private function import(): SieImport
    {
        return SieImport::query()->with('fiscalYear')->findOrFail($this->importId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
