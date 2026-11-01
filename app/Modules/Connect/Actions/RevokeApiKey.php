<?php

declare(strict_types=1);

namespace App\Modules\Connect\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\Models\ConnectApiKey;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Revokes an API key at once (ADR-032). Owners and administrators can revoke
 * any key of the company; the person who created a key can always revoke it.
 */
final class RevokeApiKey
{
    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $user, Company $company, ConnectApiKey $key): void
    {
        if ($key->company_id !== $company->id) {
            throw new AuthorizationException;
        }

        if (! $user->hasTeamPermission($company, 'connect:manage') && $key->created_by !== $user->id) {
            throw new AuthorizationException;
        }

        if ($key->revoked_at !== null) {
            return;
        }

        // Not through Eloquent's update(): an archived company's keys must still be revocable.
        DB::table('connect_api_keys')->where('id', $key->id)->update(['revoked_at' => now(), 'revoked_by' => $user->id, 'updated_at' => now()]);

        Audit::record('connect.api_key.revoked', $key, ['name' => $key->name, 'prefix' => $key->prefix], $company, $user);
    }
}
