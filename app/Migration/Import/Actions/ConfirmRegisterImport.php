<?php

declare(strict_types=1);

namespace App\Migration\Import\Actions;

use App\Migration\Import\Jobs\RunRegisterImport;
use App\Models\RegisterImport;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Step 2 of a register import (ADR-076): the user has read the report and
 * confirms. Blocked when the report has blockers; for open invoices that
 * includes a difference to the ledger balance. The work runs as a queued
 * job that carries the company id.
 */
class ConfirmRegisterImport
{
    public function __invoke(User $user, RegisterImport $import): RegisterImport
    {
        Gate::forUser($user)->authorize('confirm', $import);

        return CurrentCompany::run($import->company_id, function () use ($user, $import): RegisterImport {
            // Two clicks, or two tabs: only the request that changes ANALYSED to RUNNING starts the job.
            $claimed = DB::transaction(fn (): int => RegisterImport::query()
                ->whereKey($import->id)
                ->where('status', RegisterImport::ANALYSED)
                ->update(['status' => RegisterImport::RUNNING, 'started_at' => now(), 'error' => null]));

            if ($claimed !== 1) {
                throw ValidationException::withMessages(['import' => __('Inläsningen har redan startat.')]);
            }

            RunRegisterImport::dispatch($import->id, $import->company_id, $user->id);

            return RegisterImport::query()->findOrFail($import->id);
        });
    }
}
