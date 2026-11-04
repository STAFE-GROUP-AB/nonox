<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Console;

use App\Models\Company;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\Support\AccessPoints;
use App\Support\Audit\Audit;
use App\Support\Peppol\PeppolIdentifier;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Command;

/**
 * For the operator of the installation (ADR-050): links a company to its
 * account with the access-point provider, after the provider's customer
 * identification of that company is done (in the provider's portal or
 * Partner API). Until then the company can neither send nor receive. The
 * company's own Peppol ID is set by the company under Moduler.
 */
class LinkPeppolAccountCommand extends Command
{
    protected $signature = 'peppol:link {company : company id} {account : the provider\'s account id for the company, e.g. Qvalia\'s accountRegNo} {--unlink : remove the link}';

    protected $description = 'Link a company to its account with the Peppol access-point provider';

    public function handle(): int
    {
        $company = Company::query()->find((int) $this->argument('company'));

        if (! $company instanceof Company) {
            $this->error('No such company.');

            return self::FAILURE;
        }

        return CurrentCompany::run($company, function () use ($company): int {
            $participant = PeppolParticipant::query()->first() ?? new PeppolParticipant([
                'identifier' => PeppolIdentifier::forOrganisationNumber($company->org_number)?->toString(),
            ]);

            if ($participant->identifier === null) {
                $this->error('The company has no valid organisation number; set its Peppol ID under Moduler first.');

                return self::FAILURE;
            }

            $account = $this->option('unlink') ? null : trim((string) $this->argument('account'));
            $participant->forceFill(['provider' => $account === null ? null : AccessPoints::driver(), 'provider_account' => $account])->save();

            Audit::record('peppol.account_linked', $participant, ['provider' => $participant->provider, 'linked' => $account !== null], $company);
            $this->comment($account === null ? 'Unlinked.' : "Company `{$company->id}` linked to account `{$account}`.");

            return self::SUCCESS;
        });
    }
}
