<?php

declare(strict_types=1);

namespace App\Modules\Connect\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\ConnectModule;
use App\Modules\Connect\Models\ConnectApp;
use App\Modules\Connect\Support\ConnectSettings;
use App\Modules\ModuleManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The company's Connect settings (ADR-032): which apps may ask for access
 * and which addresses may call the API. Owners and administrators only;
 * audited by ModuleManager with before and after.
 */
final class SaveConnectSettings
{
    /**
     * @param  array<string, mixed>  $input  app_policy (all|listed), allowed_clients (list of client ids), ip_allowlist (text)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, array $input): void
    {
        if (! $user->hasTeamPermission($company, 'connect:manage')) {
            throw new AuthorizationException;
        }

        $validated = Validator::make($input, [
            'app_policy' => ['required', 'in:all,listed'],
            'allowed_clients' => ['array'],
            'allowed_clients.*' => ['string', 'uuid'],
            'ip_allowlist' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $allowlist = ConnectSettings::parseAllowlist((string) ($validated['ip_allowlist'] ?? ''));

        if ($allowlist === null) {
            throw ValidationException::withMessages(['ip_allowlist' => __('Skriv en IP-adress eller ett nät (till exempel 203.0.113.0/24) per rad.')]);
        }

        $known = ConnectApp::query()->whereIn('id', (array) ($validated['allowed_clients'] ?? []))->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

        app(ModuleManager::class)->updateSettings(ConnectModule::KEY, $company, $user, [
            'app_policy' => $validated['app_policy'],
            'allowed_clients' => array_values($known),
            'ip_allowlist' => $allowlist,
        ]);
    }
}
