<?php

declare(strict_types=1);

namespace App\Modules\Saas\Console;

use App\Modules\Saas\Support\SaasLedger;
use Illuminate\Console\Command;

/**
 * Recomputes the open months of Återkommande intäkter (ADR-080 §4). Without
 * an argument every company with NONOX SaaS on; it runs nightly, so the
 * current month follows the calendar even without a new version.
 */
class RecomputeSaasCommand extends Command
{
    protected $signature = 'saas:recompute {company? : Företagets id}';

    protected $description = 'Räknar om de öppna månaderna i Återkommande intäkter';

    public function handle(SaasLedger $ledger): int
    {
        $companies = Companies::from($this->argument('company'));

        if ($companies === null) {
            $this->error('Företaget finns inte.');

            return self::INVALID;
        }

        foreach ($companies as $company) {
            $this->line("Räknar om {$company->name}...");

            if ($company->isArchived()) {
                $this->comment('  Arkiverat, hoppas över.');

                continue;
            }

            $ledger->recompute($company);
        }

        $this->comment('Klart: '.count($companies).' företag.');

        return self::SUCCESS;
    }
}
