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

/**
 * Pins a report to the overview as a tile, or takes it off (ADR-081 gap 7,
 * E18). See ReportDefinitionPolicy::pin: the owner pins; a company report is
 * pinned for everyone who may open it, so that needs analysis:share as well.
 */
class PinReport
{
    public function __invoke(User $user, Company $company, ReportDefinition $report, bool $pinned): ReportDefinition
    {
        return CurrentCompany::run($company, function () use ($user, $company, $report, $pinned): ReportDefinition {
            abort_unless($report->company_id === $company->id, 404);
            Gate::forUser($user)->authorize('pin', [$report, $pinned]);

            return DB::transaction(function () use ($user, $company, $report, $pinned): ReportDefinition {
                $report = ReportDefinition::query()->lockForUpdate()->findOrFail($report->id);
                $report->update(['pinned' => $pinned]);
                Audit::record('analysis.report_pinned', $report, ['report_id' => $report->id, 'pinned' => $pinned], $company, $user);

                return $report;
            });
        });
    }
}
