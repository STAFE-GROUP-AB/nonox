<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Console;

use App\Modules\Payroll\Support\Tax\TaxTableImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Imports Skatteverket's general tax tables for a year from the official
 * text file (allmanna-tabeller-manad.txt), downloaded by a person from
 * skatteverket.se. The file is checked before anything is stored.
 */
class ImportTaxTablesCommand extends Command
{
    protected $signature = 'payroll:import-tax-tables {year : Året, till exempel 2026} {file : Sökväg till filen från Skatteverket}';

    protected $description = 'Läser in Skatteverkets skattetabeller för ett år';

    public function handle(TaxTableImporter $importer): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("Filen {$path} finns inte.");

            return self::FAILURE;
        }

        $this->line("Kontrollerar {$path}...");

        try {
            $result = $importer->import((int) $this->argument('year'), basename($path), (string) file_get_contents($path));
        } catch (InvalidArgumentException $exception) {
            $this->error("Filen lästes inte in: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->comment("{$result['tables']} tabeller med {$result['rows']} rader är inlästa.");

        return self::SUCCESS;
    }
}
