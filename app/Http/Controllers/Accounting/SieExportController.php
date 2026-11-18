<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Actions\Accounting\ExportSie;
use App\Models\BudgetVersion;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SieExportController
{
    /**
     * Streams the SIE file, so a large year never has to fit in memory.
     */
    public function show(Request $request, FiscalYear $fiscalYear, ExportSie $exportSie): StreamedResponse
    {
        Gate::authorize('exportSie', $fiscalYear);

        /** @var User $user */
        $user = $request->user();
        $company = Company::query()->findOrFail($fiscalYear->company_id);

        // "Ta med budget" (ADR-081 E4): off unless asked for, and only for someone who may see budgets.
        $withBudget = $request->boolean('budget');

        if ($withBudget) {
            Gate::authorize('viewAny', [BudgetVersion::class, $company]);
        }

        return response()->streamDownload(function () use ($exportSie, $user, $fiscalYear, $withBudget): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            $exportSie($user, $fiscalYear, $output, $withBudget);
            fclose($output);
        }, ExportSie::filename($company, $fiscalYear), [
            'Content-Type' => 'text/plain; charset=IBM437',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
