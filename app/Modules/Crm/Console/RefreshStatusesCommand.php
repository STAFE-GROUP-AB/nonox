<?php

declare(strict_types=1);

namespace App\Modules\Crm\Console;

use App\Models\Company;
use App\Modules\Crm\Actions\RefreshStatuses;
use App\Modules\Crm\CrmModule;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

/**
 * Applies the CRM's status rules every day (ADR-043): a prospect with its
 * first invoice becomes an active customer, an active customer without an
 * invoice for the company's set number of months becomes inactive. For one
 * company or every company with CRM on; archived companies are read-only
 * and skipped.
 */
class RefreshStatusesCommand extends Command
{
    protected $signature = 'crm:refresh-statuses {company? : company id; every company with CRM on when omitted}';

    protected $description = 'Apply the CRM status rules (prospect, active, inactive) from the ledger';

    public function handle(ModuleManager $modules, RefreshStatuses $refresh): int
    {
        $ids = $this->argument('company') !== null
            ? [(int) $this->argument('company')]
            : $modules->companiesWith(CrmModule::KEY);

        $changed = 0;

        foreach ($ids as $id) {
            // The command looks across companies; each one is refreshed in its own company.
            $company = CompanyScope::bypass(fn (): ?Company => Company::query()->find($id));

            if (! $company instanceof Company || $company->isArchived() || ! $modules->isEnabled(CrmModule::KEY, $company)) {
                continue;
            }

            $this->info("Refreshing company `{$company->id}`...");
            $changed += $refresh($company);
        }

        $this->comment('Checked '.count($ids)." companies. {$changed} accounts changed status.");

        return self::SUCCESS;
    }
}
