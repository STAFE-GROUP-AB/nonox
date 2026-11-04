<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Accounting\CreateNextFiscalYear;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Creates the next fiscal year, every morning, for every company that is not
 * archived whose latest year ends within thirty days (or ended recently).
 * Only an ordinary twelve-month year directly after the latest one; a change
 * of fiscal year (BFL 3 kap. 3 och 6 §§) is left to a person. Closing the old
 * year and its opening balances stay with the year-end (ADR-010). Safe to run
 * any number of times: each company is handled under its own lock.
 */
class CreateNextFiscalYearsCommand extends Command
{
    protected $signature = 'fiscal-years:create-next';

    protected $description = 'Skapar nästa räkenskapsår för företag vars år slutar inom 30 dagar';

    public function handle(CreateNextFiscalYear $createNextFiscalYear): int
    {
        $today = CarbonImmutable::today();
        $created = 0;
        $failed = 0;

        // The companies table is not company-scoped; each company is then handled inside its own scope.
        $companies = Company::query()->whereNull('archived_at')->orderBy('id')->get();

        foreach ($companies as $company) {
            try {
                $fiscalYear = $createNextFiscalYear->dueFor($company, $today);
            } catch (Throwable $exception) {
                $failed++;
                $this->error("Företag {$company->id}: {$exception->getMessage()}");

                continue;
            }

            if ($fiscalYear !== null) {
                $created++;
                $this->info("Företag {$company->id}: räkenskapsåret {$fiscalYear->label()} är skapat.");
            }
        }

        $this->comment("{$created} räkenskapsår skapade, {$companies->count()} företag kontrollerade, {$failed} fel.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
