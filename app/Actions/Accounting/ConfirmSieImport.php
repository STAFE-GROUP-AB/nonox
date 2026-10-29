<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\SieImport\SieImportStatus;
use App\Jobs\RunSieImport;
use App\Models\SieImport;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Step two of the SIE import: the user has read the report and confirms.
 * The file is read in by a queued job, which checks it once more.
 */
class ConfirmSieImport
{
    public function __invoke(User $user, SieImport $import): void
    {
        Gate::forUser($user)->authorize('confirm', $import);

        $queued = SieImport::query()
            ->whereKey($import->id)
            ->where('status', SieImportStatus::Analysed->value)
            ->update(['status' => SieImportStatus::Queued->value]);

        if ($queued !== 1) {
            throw ValidationException::withMessages(['import' => __('Inläsningen har redan startat.')]);
        }

        RunSieImport::dispatch($import->company_id, $import->id);
    }
}
