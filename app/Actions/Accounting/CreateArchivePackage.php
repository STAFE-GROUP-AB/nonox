<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Jobs\BuildArchivePackage;
use App\Models\ArchivePackage;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Orders an archive package for a fiscal year (AGENTS.md §10). The package
 * is built in the background; a year has at most one package being built at
 * a time, and earlier packages are kept.
 */
class CreateArchivePackage
{
    public function __invoke(User $user, FiscalYear $fiscalYear): ArchivePackage
    {
        Gate::forUser($user)->authorize('exportArchive', $fiscalYear);

        $package = CurrentCompany::run($fiscalYear->company_id, fn (): ArchivePackage => DB::transaction(function () use ($user, $fiscalYear): ArchivePackage {
            FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->firstOrFail();

            $busy = ArchivePackage::query()
                ->where('fiscal_year_id', $fiscalYear->id)
                ->whereIn('status', [ArchivePackage::QUEUED, ArchivePackage::RUNNING])
                ->exists();

            if ($busy) {
                throw ValidationException::withMessages(['archive' => __('Ett arkivpaket för :year skapas redan. Vänta tills det är klart.', ['year' => $fiscalYear->label()])]);
            }

            $package = ArchivePackage::create([
                'fiscal_year_id' => $fiscalYear->id,
                'status' => ArchivePackage::QUEUED,
                'created_by' => $user->id,
            ]);
            Audit::record('archive.package_ordered', $package, ['fiscal_year' => $fiscalYear->label()], null, $user);

            return $package;
        }));

        BuildArchivePackage::dispatch($fiscalYear->company_id, $package->id);

        return $package->refresh();
    }
}
