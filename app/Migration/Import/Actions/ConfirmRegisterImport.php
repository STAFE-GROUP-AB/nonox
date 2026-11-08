<?php

declare(strict_types=1);

namespace App\Migration\Import\Actions;

use App\Migration\Import\Jobs\RunRegisterImport;
use App\Models\RegisterImport;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Gate;

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
            /** @var RegisterImport $import */
            $import = RegisterImport::query()->findOrFail($import->id);
            $import->forceFill(['status' => RegisterImport::RUNNING, 'started_at' => now()])->save();

            RunRegisterImport::dispatch($import->id, $import->company_id, $user->id);

            return $import->fresh() ?? $import;
        });
    }
}
