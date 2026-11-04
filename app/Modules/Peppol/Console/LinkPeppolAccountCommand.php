<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Console;

use App\Models\Company;
use App\Modules\Peppol\Domain\AccessPointFailed;
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
    protected $signature = 'peppol:link {company : company id} {account : the provider\'s account id for the company, e.g. Qvalia\'s accountRegNo} {--unlink : remove the link} {--no-webhook : do not subscribe the webhook}';

    protected $description = 'Link a company to its account with the Peppol access-point provider';

    public function handle(): int
    {
        $company = Company::query()->find((int) $this->argument('company'));

        if (! $company instanceof Company) {
            $this->error('No such company.');

            return self::FAILURE;
        }

        return CurrentCompany::run($company, function () use ($company): int {
            $participant = PeppolParticipant::query()->first();
            $identifier = $participant instanceof PeppolParticipant ? $participant->identifier : PeppolIdentifier::forOrganisationNumber($company->org_number)?->toString();

            if ($identifier === null) {
                $this->error('The company has no valid organisation number; set its Peppol ID under Moduler first.');

                return self::FAILURE;
            }

            $participant ??= new PeppolParticipant(['identifier' => $identifier]);
            $account = $this->option('unlink') ? null : trim((string) $this->argument('account'));
            $participant->forceFill(['provider' => $account === null ? null : AccessPoints::driver(), 'provider_account' => $account, 'webhook_id' => null, 'webhook_secret' => null])->save();

            // The provider's events for this account come to the installation's webhook, signed with the account's own secret.
            if ($account !== null && ! $this->option('no-webhook')) {
                try {
                    $subscription = AccessPoints::make()->subscribeWebhook($participant, route('peppol.webhook', ['driver' => AccessPoints::driver()]));

                    if ($subscription !== null) {
                        $participant->forceFill(['webhook_id' => $subscription['id'], 'webhook_secret' => $subscription['secret']])->save();
                        $this->info('Webhook subscribed.');
                    }
                } catch (AccessPointFailed $exception) {
                    $this->warn("The webhook could not be subscribed: {$exception->getMessage()} Polling (peppol:sync) still works.");
                }
            }

            Audit::record('peppol.account_linked', $participant, ['provider' => $participant->provider, 'linked' => $account !== null, 'webhook' => $participant->webhook_id !== null], $company);
            $this->comment($account === null ? 'Unlinked.' : "Company `{$company->id}` linked to account `{$account}`.");

            return self::SUCCESS;
        });
    }
}
