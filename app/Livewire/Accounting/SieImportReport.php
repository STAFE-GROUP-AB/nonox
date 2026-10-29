<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Accounting\ConfirmSieImport;
use App\Actions\Accounting\ImportSie;
use App\Domain\Accounting\SieImport\SieImportStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\SieImport;
use App\Models\User;
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
        ]);
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
