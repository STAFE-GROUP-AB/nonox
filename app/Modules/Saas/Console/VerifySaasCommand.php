<?php

declare(strict_types=1);

namespace App\Modules\Saas\Console;

use App\Modules\Saas\Models\SaasMonth;
use App\Modules\Saas\Support\SaasLedger;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Command;

/**
 * Proves that every closed month reproduces (ADR-080 §4): it is recomputed
 * from the versions known when it was closed and its frozen rates, and
 * compared row by row. A difference is a P1 bug. The result goes to the audit
 * log (months, ok, and the months that differ: dates only) and is shown on
 * Månader, like ledger:verify. Runs nightly.
 */
class VerifySaasCommand extends Command
{
    protected $signature = 'saas:verify {company? : Företagets id}';

    protected $description = 'Kontrollerar att stängda månader i Återkommande intäkter går att räkna fram igen';

    public function handle(SaasLedger $ledger): int
    {
        $companies = Companies::from($this->argument('company'));

        if ($companies === null) {
            $this->error('Företaget finns inte.');

            return self::INVALID;
        }

        $failed = 0;

        foreach ($companies as $company) {
            $this->line("Kontrollerar {$company->name}...");
            $problems = $ledger->verify($company);
            $closed = CurrentCompany::run($company, fn (): int => SaasMonth::query()->where('status', SaasMonth::CLOSED)->count());

            Audit::record('saas.verified', null, ['ok' => $problems === [], 'closed_months' => $closed, 'differing_months' => array_keys($problems)], $company);

            if ($problems === []) {
                $this->info("  OK: {$closed} stängda månader stämmer.");

                continue;
            }

            $failed++;

            foreach ($problems as $month => $issues) {
                $this->error("  {$month}: ".implode(', ', $issues));
            }
        }

        $this->comment($failed === 0 ? 'Alla stängda månader stämmer.' : "Avvikelser i {$failed} företag.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
