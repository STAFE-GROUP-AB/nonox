<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Console;

use App\Modules\Payroll\Support\Tax\TaxTableImporter;
use App\Modules\Payroll\Support\Tax\TaxTableOpenData;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;

/**
 * Imports Skatteverket's general tax tables for a year, either from the
 * official text file (allmanna-tabeller-manad.txt) downloaded by a person
 * from skatteverket.se, or with --download from Skatteverket's open data.
 * The tables are checked before anything is stored, and a year is imported
 * once.
 */
class ImportTaxTablesCommand extends Command
{
    protected $signature = 'payroll:import-tax-tables
        {year : Året, till exempel 2027}
        {file? : Sökväg till filen från Skatteverket}
        {--download : Hämta tabellerna från Skatteverkets öppna data i stället för från en fil}';

    protected $description = 'Läser in Skatteverkets skattetabeller för ett år';

    public function handle(TaxTableImporter $importer, TaxTableOpenData $openData): int
    {
        $year = (int) $this->argument('year');
        $path = $this->argument('file');

        if ($this->option('download') === ($path !== null)) {
            $this->error('Ange antingen en fil från Skatteverket eller --download.');

            return self::INVALID;
        }

        if ($this->option('download')) {
            $this->line("Hämtar skattetabellerna för {$year} från Skatteverkets öppna data...");

            try {
                $contents = $openData->fetch($year);
            } catch (RequestException|ConnectionException|InvalidArgumentException $exception) {
                $this->error("Tabellerna kunde inte hämtas: {$exception->getMessage()}");

                return self::FAILURE;
            }

            if ($contents === null) {
                $this->warn("Skatteverket har inte publicerat skattetabellerna för {$year} ännu. De kommer i december året före.");

                return self::FAILURE;
            }

            return $this->import($importer, $year, "skatteverket-oppna-data-{$year}.txt", $contents);
        }

        $path = (string) $path;

        if (! is_file($path)) {
            $this->error("Filen {$path} finns inte.");

            return self::FAILURE;
        }

        $this->line("Kontrollerar {$path}...");

        return $this->import($importer, $year, basename($path), (string) file_get_contents($path));
    }

    private function import(TaxTableImporter $importer, int $year, string $name, string $contents): int
    {
        try {
            $result = $importer->import($year, $name, $contents);
        } catch (InvalidArgumentException $exception) {
            $this->error("Tabellerna lästes inte in: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->comment("{$result['tables']} tabeller med {$result['rows']} rader är inlästa för {$year}.");

        return self::SUCCESS;
    }
}
