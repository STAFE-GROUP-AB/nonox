<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\CrmSettings;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\PruneCandidates;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * The CRM step of privacy:prune (GDPR art. 5.1 e, ADR-043): in every company
 * with the module on, contact people and prospects with no activity for the
 * company's setting (default 24 months) are erased. "Att göra" lists them 30
 * days ahead (CrmTodos), so someone can keep a contact by logging an
 * activity. A company with the module off is left alone: nobody sees the
 * warning there.
 */
final class PruneCrm
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly EraseCrmData $erase,
    ) {}

    /** @return array{people: int, prospects: int} */
    public function __invoke(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $counts = ['people' => 0, 'prospects' => 0];

        foreach ($this->modules->companiesWith(CrmModule::KEY) as $companyId) {
            $company = Company::query()->find($companyId);

            if (! $company instanceof Company) {
                continue;
            }

            $cutoff = $now->subMonthsNoOverflow(CrmSettings::for($company)['prune_after_months']);

            CurrentCompany::run($company, function () use ($company, $cutoff, &$counts): void {
                PruneCandidates::people($cutoff)->get()->each(function (Person $person) use ($company, &$counts): void {
                    $this->erase->person(null, $company, $person);
                    $counts['people']++;
                });

                PruneCandidates::prospects($cutoff)->get()->each(function (Account $account) use ($company, &$counts): void {
                    $this->erase->prospect(null, $company, $account);
                    $counts['prospects']++;
                });
            });
        }

        return $counts;
    }
}
