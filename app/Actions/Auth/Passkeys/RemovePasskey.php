<?php

declare(strict_types=1);

namespace App\Actions\Auth\Passkeys;

use App\Domain\Auth\PasskeyRequirement;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Passkey;

/**
 * Removes one of the user's own passkeys. The last one stays while a passkey
 * is required for the user: without it they could not sign in.
 */
class RemovePasskey
{
    public function __invoke(User $user, int $passkeyId): Passkey
    {
        return DB::transaction(function () use ($user, $passkeyId): Passkey {
            /** @var Passkey $passkey */
            $passkey = $user->passkeys()->whereKey($passkeyId)->lockForUpdate()->firstOrFail();

            if ($user->passkeys()->count() === 1 && PasskeyRequirement::appliesTo($user)) {
                throw PasskeyCeremony::failed(__('Du behöver minst en passkey för att kunna logga in. Lägg till en ny innan du tar bort den här.'));
            }

            app(DeletePasskey::class)($user, $passkey);

            Audit::record('passkey.removed', $passkey, ['name' => $passkey->name], null, $user);

            return $passkey;
        });
    }
}
