<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\ArchivedCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Takes a company out of the archive, so it can be written to again
 * (ADR-024). Owner only.
 */
class UnarchiveCompany
{
    public function __invoke(User $user, Company $company): void
    {
        Gate::forUser($user)->authorize('unarchive', $company);

        DB::transaction(function () use ($user, $company): void {
            /** @var Company $locked */
            $locked = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isArchived()) {
                return;
            }

            $archivedAt = $locked->archived_at;
            $locked->forceFill(['archived_at' => null])->save();
            $company->setRawAttributes($locked->getAttributes(), true);

            Audit::record('company.unarchived', $locked, ['archived_at' => $archivedAt?->toIso8601String()], $locked, $user);
        });

        ArchivedCompany::forget($company->id);
    }
}
