<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\FiscalYearRules;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Creates the twelve-month fiscal year that follows the latest one, so the
 * years never overlap or leave a gap.
 */
class CreateNextFiscalYear
{
    public function __invoke(User $user, Company $company): FiscalYear
    {
        Gate::forUser($user)->authorize('create', [FiscalYear::class, $company]);

        return CurrentCompany::run($company, function () use ($company, $user): FiscalYear {
            $latest = FiscalYear::query()->orderByDesc('end_date')->first();

            if (! $latest instanceof FiscalYear) {
                throw ValidationException::withMessages([
                    'fiscal_year' => __('Företaget har inget räkenskapsår ännu.'),
                ]);
            }

            $start = $latest->end_date->addDay();
            $end = $start->addMonths(FiscalYearRules::NORMAL_MONTHS)->subDay();

            $fiscalYear = FiscalYear::create(['start_date' => $start, 'end_date' => $end]);

            Audit::record('fiscal_year.created', $fiscalYear, [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ], $company, $user);

            return $fiscalYear;
        });
    }
}
