<?php

declare(strict_types=1);

namespace App\Modules\Crm\Console;

use App\Models\Company;
use App\Modules\Crm\Actions\CreateWinBackTasks;
use App\Modules\Crm\CrmModule;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

/**
 * Makes the "Vinn tillbaka" tasks for lost deals and lost accounts whose
 * win-back date has come (ADR-043 stage 2), once a day. For one company or
 * every company with CRM on; archived companies are read-only and skipped.
 * Idempotent: a date gives one task however often this runs.
 */
class WinBackCommand extends Command
{
    protected $signature = 'crm:win-back {company? : company id; every company with CRM on when omitted}';

    protected $description = 'Create "Vinn tillbaka" tasks for lost deals and accounts whose win-back date has come';

    public function handle(ModuleManager $modules, CreateWinBackTasks $create): int
    {
        $ids = $this->argument('company') !== null
            ? [(int) $this->argument('company')]
            : $modules->companiesWith(CrmModule::KEY);

        $made = 0;

        foreach ($ids as $id) {
            // The command looks across companies; each one is handled in its own company.
            $company = CompanyScope::bypass(fn (): ?Company => Company::query()->find($id));

            if (! $company instanceof Company || $company->isArchived() || ! $modules->isEnabled(CrmModule::KEY, $company)) {
                continue;
            }

            $this->info("Checking company `{$company->id}`...");
            $made += $create($company);
        }

        $this->comment('Checked '.count($ids)." companies. {$made} win-back tasks created.");

        return self::SUCCESS;
    }
}
