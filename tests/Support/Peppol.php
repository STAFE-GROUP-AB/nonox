<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inbox\InboxModule;
use App\Modules\ModuleManager;
use App\Modules\Peppol\Domain\RegistrationStatus;
use App\Modules\Peppol\Drivers\LogAccessPoint;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\PeppolModule;
use App\Support\Peppol\PeppolIdentifier;
use App\Support\Tenancy\CurrentCompany;

/**
 * Peppol test setup with the log access point: nothing reaches a real
 * access point or the Peppol network.
 */
final class Peppol
{
    /** Turns the module on and links the company to an account, as the operator would with peppol:link. */
    public static function ready(Company $company, User $owner, string $account, bool $receive = false): PeppolParticipant
    {
        $modules = app(ModuleManager::class);
        $modules->enable(PeppolModule::KEY, $company, $owner);

        if ($receive) {
            $modules->enable(InboxModule::KEY, $company, $owner);
        }

        return CurrentCompany::run($company, function () use ($company, $account, $receive): PeppolParticipant {
            $participant = PeppolParticipant::query()->first() ?? new PeppolParticipant;
            $participant->fill([
                'identifier' => PeppolIdentifier::forOrganisationNumber($company->org_number)?->toString(),
                'receive_enabled' => $receive,
            ]);
            $participant->forceFill([
                'provider' => LogAccessPoint::KEY,
                'provider_account' => $account,
                'registration_status' => $receive ? RegistrationStatus::Registered : RegistrationStatus::NotRegistered,
            ])->save();

            return $participant;
        });
    }
}
