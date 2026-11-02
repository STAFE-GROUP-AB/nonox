<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Console;

use App\Models\Company;
use App\Modules\Payroll\Actions\NotifyPayrollResponsibles;
use App\Modules\Payroll\Notifications\PayrollYearNotice;
use App\Modules\Payroll\Support\Tax\TaxTableImporter;
use App\Modules\Payroll\Support\Tax\TaxTableOpenData;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Runs every night: imports Skatteverket's monthly tax tables for the
 * current year and, from October, for the next year, as soon as they are
 * published in Skatteverket's open data. A year that is already imported is
 * never fetched or touched again, so this only costs a request a night
 * while a year is still missing. The tables are Skatteverket's own data and
 * pass the same checks as a file import; unlike the payroll rules they are
 * not an interpretation that a person has to verify.
 */
class FetchPublishedTaxTablesCommand extends Command
{
    protected $signature = 'payroll:fetch-published-tax-tables';

    protected $description = 'Hämtar årets och nästa års skattetabeller från Skatteverket när de är publicerade';

    public function handle(TaxTableImporter $importer, TaxTableOpenData $openData, NotifyPayrollResponsibles $notify): int
    {
        $failed = false;

        foreach ($this->years() as $year) {
            if (DB::table('tax_table_imports')->where('year', $year)->exists()) {
                $this->line("Skattetabellerna för {$year} är redan inlästa.");

                continue;
            }

            $this->line("Letar efter skattetabellerna för {$year} hos Skatteverket...");

            try {
                $contents = $openData->fetch($year);

                if ($contents === null) {
                    $this->line("Skatteverket har inte publicerat skattetabellerna för {$year} ännu.");

                    continue;
                }

                $this->guardComplete($year, $contents);
                $result = $importer->import($year, "skatteverket-oppna-data-{$year}.txt", $contents);
            } catch (RequestException|ConnectionException|InvalidArgumentException $exception) {
                $failed = true;
                Log::warning('payroll.tax_tables_fetch_failed', ['year' => $year, 'error' => $exception->getMessage()]);
                $this->error("Skattetabellerna för {$year} lästes inte in: {$exception->getMessage()}");

                continue;
            }

            Audit::record('payroll.tax_tables_imported', null, ['year' => $year, 'source' => TaxTableOpenData::URL, 'tables' => $result['tables'], 'rows' => $result['rows']]);
            $this->comment("{$result['tables']} tabeller med {$result['rows']} rader är inlästa för {$year}.");
            $told = $notify(fn (Company $company): PayrollYearNotice => PayrollYearNotice::taxTables($company->id, (string) $company->name, $year));
            $this->line("{$told} löneansvariga har fått en notis.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * An imported year can never be replaced, so a year that Skatteverket has
     * only published in part must not be taken: it needs at least as many
     * tables as the year before. A person can still import it by hand.
     */
    private function guardComplete(int $year, string $contents): void
    {
        $previous = (int) DB::table('tax_table_imports')->where('year', $year - 1)->max('table_count');
        $tables = count(array_unique(array_map(fn (string $line): string => substr($line, 3, 2), array_filter(explode("\n", $contents)))));

        if ($tables < $previous) {
            throw new InvalidArgumentException("Skatteverket's open data has {$tables} tables for {$year}, fewer than the {$previous} of the year before. Waiting for the whole year.");
        }
    }

    /** @return list<int> */
    private function years(): array
    {
        $today = CarbonImmutable::now('Europe/Stockholm');

        return $today->month >= 10 ? [$today->year, $today->year + 1] : [$today->year];
    }
}
