<?php

declare(strict_types=1);

namespace App\Modules\Saas\Jobs;

use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\Saas\SaasModule;
use App\Modules\Saas\Support\SaasLedger;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\RunsForCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Recomputes the open months of one company after a new contract version
 * (ADR-080 §4), with the company given explicitly (AGENTS.md §6.0). One
 * waiting job per company is enough: it reads every version when it runs.
 */
class RecomputeSaas implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsForCompany;

    public int $tries = 3;

    public function __construct(public int $companyId) {}

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }

    public function handle(SaasLedger $ledger, ModuleManager $modules): void
    {
        CurrentCompany::set($this->companyId);

        if (! $modules->isEnabled(SaasModule::KEY, $this->companyId)) {
            return;
        }

        $company = Company::query()->find($this->companyId);

        if (! $company instanceof Company || $company->isArchived()) {
            return;
        }

        $ledger->recompute($company);
    }
}
