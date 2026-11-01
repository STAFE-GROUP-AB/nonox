<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Domain\Company\ArchiveReadiness;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\ArchivedCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Delete" a company the only way AGENTS.md §3.5 allows: archive it. The
 * company becomes read-only (ArchivedCompany) and keeps every row for the
 * retention period (BFL 7 kap.); nothing is deleted. Owner only.
 *
 * Every fiscal year should have an up-to-date archive package first. When
 * one is missing or older than the year's last posting, the owner must
 * confirm that the räkenskapsinformation is exported another way.
 */
class ArchiveCompany
{
    public function __construct(private readonly ArchiveReadiness $readiness) {}

    public function __invoke(User $user, Company $company, bool $confirmedExport = false): void
    {
        Gate::forUser($user)->authorize('archive', $company);

        $years = $this->readiness->years($company);
        $missing = array_values(array_map(fn (array $row): string => $row['year']->label(), array_filter($years, fn (array $row): bool => ! $row['current'])));

        if ($missing !== [] && ! $confirmedExport) {
            throw ValidationException::withMessages(['export' => __('Arkivpaket saknas eller är inaktuellt för :years. Skapa och ladda ner dem först, eller bekräfta att du har sparat räkenskapsinformationen på annat sätt.', ['years' => implode(', ', $missing)])]);
        }

        DB::transaction(function () use ($user, $company, $years, $missing, $confirmedExport): void {
            /** @var Company $locked */
            $locked = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

            if ($locked->isArchived()) {
                return;
            }

            $locked->forceFill(['archived_at' => now()])->save();
            $company->setRawAttributes($locked->getAttributes(), true);

            Audit::record('company.archived', $locked, [
                'archive_packages' => array_values(array_filter(array_map(fn (array $row): ?int => $row['current'] ? $row['package']?->id : null, $years))),
                'years_without_current_package' => $missing,
                'confirmed_export' => $confirmedExport,
            ], $locked, $user);
        });

        ArchivedCompany::forget($company->id);
    }
}
