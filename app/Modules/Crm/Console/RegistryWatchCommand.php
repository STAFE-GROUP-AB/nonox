<?php

declare(strict_types=1);

namespace App\Modules\Crm\Console;

use App\Models\Company;
use App\Modules\Crm\Actions\CreateRegistryNotices;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\CrmSettings;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

/**
 * Monitoring (ADR-047): turns the company register's changes for each
 * company's accounts into "Ändring hos kunden" tasks, once a day after the
 * import. For one company or every company with CRM on and monitoring on;
 * archived companies are skipped. Idempotent.
 */
class RegistryWatchCommand extends Command
{
    protected $signature = 'crm:registry-watch {company? : company id; every company with CRM on when omitted}';

    protected $description = 'Create "Ändring hos kunden" tasks for changes in the company register';

    public function handle(ModuleManager $modules, CreateRegistryNotices $notices): int
    {
        $ids = $this->argument('company') !== null
            ? [(int) $this->argument('company')]
            : $modules->companiesWith(CrmModule::KEY);

        $made = 0;

        foreach ($ids as $id) {
            // The command looks across companies; each one is handled in its own company.
            $company = CompanyScope::bypass(fn (): ?Company => Company::query()->find($id));

            if (! $company instanceof Company || $company->isArchived() || ! $modules->isEnabled(CrmModule::KEY, $company) || CrmSettings::for($company)['registry_monitoring'] !== 1) {
                continue;
            }

            $this->info("Checking company `{$company->id}`...");
            $made += $notices($company);
        }

        $this->comment('Checked '.count($ids)." companies. {$made} change tasks created.");

        return self::SUCCESS;
    }
}
