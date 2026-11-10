<?php

declare(strict_types=1);

namespace App\Actions\AnnualReport;

use App\Actions\Accounting\CreateNextFiscalYear;
use App\Domain\Company\ReportingFramework;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * The checks every write to a K3 annual report's texts shares (S1-P9):
 * fiscal-years:manage, as SaveAnnualReport; a K3 year; and no change once
 * the year is closed and the meeting has adopted the report (the same fact
 * that locks the year's policies, ADR-059 A.3a). Called inside the caller's
 * transaction, with the lock order of SaveAnnualReport and
 * ChangeReportingFramework (advisory lock, then the year's row).
 */
final class K3ReportWrite
{
    /**
     * The year's company. Another company's fiscal year answers 404, never
     * 403, so its existence is not revealed (AGENTS.md §13.2).
     *
     * @throws ModelNotFoundException<FiscalYear>
     * @throws AuthorizationException
     */
    public static function company(User $user, FiscalYear $fiscalYear): Company
    {
        $company = Company::query()->find($fiscalYear->company_id);

        if ($company === null || ! $user->belongsToTeam($company)) {
            throw (new ModelNotFoundException)->setModel(FiscalYear::class, [$fiscalYear->id]);
        }

        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }

        return $company;
    }

    /**
     * The year's annual report, created when it does not exist yet, locked
     * for the rest of the transaction.
     *
     * @throws ValidationException
     */
    public static function lockedReport(User $user, Company $company, FiscalYear $fiscalYear): AnnualReport
    {
        CreateNextFiscalYear::lock($company->id);
        $locked = FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->firstOrFail();

        if ($locked->reporting_framework !== ReportingFramework::K3) {
            throw ValidationException::withMessages(['report' => __('Uppgifterna för en årsredovisning enligt K3 sparas bara för ett räkenskapsår som följer K3. Räkenskapsåret :year följer :framework.', ['year' => $locked->label(), 'framework' => $locked->reporting_framework->value])]);
        }

        $report = AnnualReport::query()->where('fiscal_year_id', $locked->id)->lockForUpdate()->first()
            ?? AnnualReport::create(['fiscal_year_id' => $locked->id, 'signatories' => [], 'updated_by' => $user->id])->refresh();
        $adopted = $report->adoption_date;

        if (! $locked->isOpen() && $adopted !== null && $adopted->toDateString() <= CarbonImmutable::today('Europe/Stockholm')->toDateString()) {
            throw ValidationException::withMessages(['report' => __('Räkenskapsåret :year är stängt och årsredovisningen fastställdes :date. Den kan inte ändras längre.', ['year' => $locked->label(), 'date' => $adopted->toDateString()])]);
        }

        return $report;
    }
}
