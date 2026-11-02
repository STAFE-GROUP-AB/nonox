<?php

declare(strict_types=1);

namespace App\Modules\Connect\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\Models\ConnectSftpKey;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Stops an SSH key from logging in to the SFTP drop (ADR-032, "SFTP"). It
 * takes effect at the next login, since every login asks NONOX. The key is
 * kept as revoked, for the history. No passkey is needed to take access away.
 */
final class RemoveSftpKey
{
    /** @throws AuthorizationException */
    public function __invoke(User $user, Company $company, ConnectSftpKey $key): void
    {
        if (! $user->hasTeamPermission($company, 'connect:manage') || $key->company_id !== $company->id) {
            throw new AuthorizationException;
        }

        if ($key->revoked_at !== null) {
            return;
        }

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $key): void {
            $key->forceFill(['revoked_at' => now(), 'revoked_by' => $user->id])->save();

            Audit::record('connect.sftp.key_removed', $key, [
                'name' => $key->name,
                'fingerprint' => $key->fingerprint,
            ], $company, $user);
        }));
    }
}
