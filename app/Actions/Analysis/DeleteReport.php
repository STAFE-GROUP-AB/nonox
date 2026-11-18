<?php

declare(strict_types=1);

namespace App\Actions\Analysis;

use App\Models\Company;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Deletes a saved report (ADR-081 gap 7, E18): the owner's, or a company report by whoever may share. */
class DeleteReport
{
    public function __invoke(User $user, Company $company, ReportDefinition $report): void
    {
        CurrentCompany::run($company, function () use ($user, $company, $report): void {
            abort_unless($report->company_id === $company->id, 404);
            Gate::forUser($user)->authorize('delete', $report);

            DB::transaction(function () use ($user, $company, $report): void {
                Audit::record('analysis.report_deleted', $report, ['report_id' => $report->id, 'dataset' => $report->dataset, 'visibility' => $report->visibility->value], $company, $user);
                $report->delete();
            });
        });
    }
}
