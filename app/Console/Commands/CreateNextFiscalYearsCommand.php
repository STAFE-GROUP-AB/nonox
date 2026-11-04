<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Accounting\CreateNextFiscalYear;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

        $checked = 0;

        // One query across every company for the latest end date (fiscal_years is read without the company
        // scope on purpose): only companies whose latest year ends within thirty days or ended within the last
        // year can be due. Each is then handled inside its own scope by dueFor(), which checks exactly.
        $latestEnds = DB::table('fiscal_years')
            ->select('company_id')
            ->groupBy('company_id')
            ->havingRaw('max(end_date) between ? and ?', [
                $today->subYear()->subDay()->toDateString(),
                $today->addDays(CreateNextFiscalYear::DAYS_AHEAD)->toDateString(),
            ]);

        $companies = Company::query()->whereNull('archived_at')->whereIn('id', $latestEnds);

        foreach ($companies->lazyById() as $company) {
            $checked++;

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

        $this->comment("{$created} räkenskapsår skapade, {$checked} företag kontrollerade, {$failed} fel.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
