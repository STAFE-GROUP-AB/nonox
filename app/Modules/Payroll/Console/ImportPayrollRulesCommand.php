<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Console;

use App\Models\Company;
use App\Modules\Payroll\Actions\ImportPayrollRules;
use App\Modules\Payroll\Actions\NotifyPayrollResponsibles;
use App\Modules\Payroll\Notifications\PayrollYearNotice;
use Illuminate\Console\Command;

/**
 * Reads a year's rule file (app/Modules/Payroll/database/data/{year}/rules.json),
 * or every year's when no year is given, into payroll_rule_versions as
 * unverified versions. A version someone has checked is never touched.
 * Rules count only after they are checked. Runs every night from the
 * scheduler, so a new year's file reaches every installation after deploy.
 */
class ImportPayrollRulesCommand extends Command
{
    protected $signature = 'payroll:import-rules {year? : Året, till exempel 2027. Utan år läses alla år}';

    protected $description = 'Läser in lönesatser som overifierade regler';

    public function handle(ImportPayrollRules $import, NotifyPayrollResponsibles $notify): int
    {
        $year = $this->argument('year');
        $years = $year === null ? ImportPayrollRules::years() : [(int) $year];

        foreach ($years as $one) {
            if (! is_file(ImportPayrollRules::path($one))) {
                $this->error('Filen '.ImportPayrollRules::path($one).' finns inte.');

                return self::FAILURE;
            }

            $this->info("Läser reglerna för {$one}...");
            $result = $import($one, fn (array $rule) => $this->line("Läser {$rule['key']} från {$rule['valid_from']}...", verbosity: 'v'));
            $this->comment("{$one}: {$result['imported']} regler inlästa som overifierade, {$result['unchanged']} oförändrade, {$result['checked']} redan kontrollerade lämnades orörda.");

            if ($result['imported'] > 0) {
                $told = $notify(fn (Company $company): PayrollYearNotice => PayrollYearNotice::rules($company->id, (string) $company->name, $one, $result['imported']));
                $this->line("{$told} löneansvariga har fått en notis.");
            }
        }

        return self::SUCCESS;
    }
}
