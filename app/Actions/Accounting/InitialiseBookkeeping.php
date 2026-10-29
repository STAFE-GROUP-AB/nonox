<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\FiscalYearRules;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * Steps 3-5 of creating a company (AGENTS.md §3.4): the chart of accounts, the
 * first fiscal year and the default voucher series. Safe to run again.
 */
class InitialiseBookkeeping
{
    public function __construct(
        private SeedChartOfAccounts $seedChartOfAccounts,
        private CreateDefaultVoucherSeries $createDefaultVoucherSeries,
    ) {}

    public function __invoke(Company $company, ?User $user = null, ?CarbonImmutable $today = null): void
    {
        $accounts = ($this->seedChartOfAccounts)($company);
        $series = ($this->createDefaultVoucherSeries)($company);
        $fiscalYear = $this->createFirstFiscalYear($company, $today ?? CarbonImmutable::today());

        if ($accounts === 0 && $series === 0 && $fiscalYear === null) {
            return;
        }

        Audit::record('company.bookkeeping_initialised', $company, [
            'accounts' => $accounts,
            'voucher_series' => $series,
            'fiscal_year' => $fiscalYear?->label(),
            'bas_version' => $company->bas_version,
        ], $company, $user);
    }

    private function createFirstFiscalYear(Company $company, CarbonImmutable $today): ?FiscalYear
    {
        return CurrentCompany::run($company, function () use ($company, $today): ?FiscalYear {
            if (FiscalYear::query()->exists()) {
                return null;
            }

            [$start, $end] = FiscalYearRules::yearContaining($today, $company->fiscal_year_start_month);

            return FiscalYear::create(['start_date' => $start, 'end_date' => $end]);
        });
    }
}
