<?php

declare(strict_types=1);

namespace App\Modules\Crm\Console;

use App\Models\Company;
use App\Modules\Crm\Actions\RunAgreementReminders;
use App\Modules\Crm\CrmModule;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

/**
 * The agreement register's daily run (ADR-046): renews agreements whose
 * period has ended, makes the reminder tasks before notice deadlines, and
 * prunes signing links that were never signed. For one company or every
 * company with CRM on; archived companies are read-only and skipped.
 * Idempotent.
 */
class AgreementsCommand extends Command
{
    protected $signature = 'crm:agreements {company? : company id; every company with CRM on when omitted}';

    protected $description = 'Renew agreements, create reminder tasks before notice deadlines and prune unsigned signing links';

    public function handle(ModuleManager $modules, RunAgreementReminders $run): int
    {
        $ids = $this->argument('company') !== null
            ? [(int) $this->argument('company')]
            : $modules->companiesWith(CrmModule::KEY);

        $totals = [0, 0, 0];

        foreach ($ids as $id) {
            // The command looks across companies; each one is handled in its own company.
            $company = CompanyScope::bypass(fn (): ?Company => Company::query()->find($id));

            if (! $company instanceof Company || $company->isArchived() || ! $modules->isEnabled(CrmModule::KEY, $company)) {
                continue;
            }

            $this->info("Checking company `{$company->id}`...");

            foreach ($run($company) as $index => $count) {
                $totals[$index] += $count;
            }
        }

        $this->comment('Checked '.count($ids)." companies. {$totals[0]} agreements renewed, {$totals[1]} reminders created, {$totals[2]} unsigned links pruned.");

        return self::SUCCESS;
    }
}
