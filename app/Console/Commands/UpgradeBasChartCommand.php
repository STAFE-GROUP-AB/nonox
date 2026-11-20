<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Accounting\UpgradeBasChart;
use App\Models\Company;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * The yearly BAS routine: when a new template folder `database/data/bas/<year>/`
 * has been added, renames companies' accounts to the new official names where
 * the name still is the one from their previous template, and records the new
 * BAS version on the company. With --fix-names the version stays and names
 * seeded before the official-list check are corrected (run once per instance
 * after that release). Run with --dry-run first: it prints counts per company
 * id and changes nothing.
 */
class UpgradeBasChartCommand extends Command
{
    protected $signature = 'bas:upgrade {version : The new BAS version, for example 2027} {--dry-run : Only count, change nothing} {--fix-names : Correct names inside the version the companies are on instead of moving them on}';

    protected $description = 'Flyttar företagens kontoplan till en ny BAS-version utan att röra namn användaren själv har ändrat';

    public function handle(UpgradeBasChart $upgrade): int
    {
        try {
            $year = UpgradeBasChart::year((string) $this->argument('version'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! UpgradeBasChart::exists($year)) {
            $this->error("There is no template folder database/data/bas/{$year}/.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $fixNames = (bool) $this->option('fix-names');
        $renamed = 0;
        $handled = 0;
        $failed = 0;

        foreach (Company::query()->whereNull('archived_at')->lazyById() as $company) {
            try {
                $result = $upgrade($company, $year, $dryRun, $fixNames);
            } catch (Throwable $exception) {
                $failed++;
                $this->error("Företag {$company->id}: {$exception->getMessage()}");

                continue;
            }

            if ($result['skipped'] !== null) {
                $this->line("Företag {$company->id}: hoppas över ({$result['skipped']})");

                continue;
            }

            $handled++;
            $renamed += $result['renamed'];
            $this->line("Företag {$company->id}: {$result['renamed']} namn byts, {$result['kept']} egna namn lämnas");
        }

        $verb = $dryRun ? 'skulle få' : 'fick';
        $target = $fixNames ? "rättade namn enligt BAS {$year}" : "BAS {$year}";
        $this->comment("{$handled} företag {$verb} {$target}, {$renamed} kontonamn byts.".($dryRun ? ' Torrkörning: inget ändrades.' : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
