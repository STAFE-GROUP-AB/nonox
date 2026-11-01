<?php

declare(strict_types=1);

namespace App\Modules\Connect\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\ConnectModule;
use App\Modules\Connect\Models\ConnectApp;
use App\Modules\Connect\Models\ConnectGrant;
use App\Modules\Connect\Support\ConnectScopes;
use App\Modules\Connect\Support\ConnectSettings;
use App\Modules\Connect\Support\PasskeyStepUp;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lets an app into one company (ADR-032). Only an owner or administrator
 * (connect:manage), only with a passkey confirmed in the last five minutes,
 * only while Connect is on, only for an app the company allows, and only
 * with scopes the person holds themselves.
 */
final class ApproveConnection
{
    /**
     * @param  list<string>  $scopes  requested scopes
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, ConnectApp $client, array $scopes): ConnectGrant
    {
        if (! $user->hasTeamPermission($company, 'connect:manage')) {
            throw new AuthorizationException(__('Bara ägare och administratörer kan ansluta appar till företaget.'));
        }

        if ($company->isArchived()) {
            throw ValidationException::withMessages(['company' => __('Företaget är arkiverat. Det går inte att ansluta appar till det.')]);
        }

        app(ModuleManager::class)->ensureEnabled(ConnectModule::KEY, $company);

        if ($client->revoked || ! ConnectSettings::allowsClient($company, $client->getKey())) {
            throw ValidationException::withMessages(['company' => __('Företaget tillåter inte den här appen. En administratör kan ändra det under Moduler.')]);
        }

        $granted = array_values(array_filter(ConnectScopes::filter($scopes), fn (string $scope): bool => $user->hasTeamPermission($company, $scope)));

        if ($granted === []) {
            throw ValidationException::withMessages(['scopes' => __('Du har inte själv någon av behörigheterna appen ber om, så du kan inte ge den dem.')]);
        }

        PasskeyStepUp::consume($user);

        return CurrentCompany::run($company, fn (): ConnectGrant => DB::transaction(function () use ($user, $company, $client, $granted): ConnectGrant {
            $grant = ConnectGrant::create([
                'user_id' => $user->id,
                'client_id' => $client->getKey(),
                'scopes' => $granted,
            ]);

            Audit::record('connect.grant.created', $grant, [
                'client_id' => $client->getKey(),
                'client_name' => $client->name,
                'scopes' => $granted,
            ], $company, $user);

            return $grant;
        }));
    }
}
