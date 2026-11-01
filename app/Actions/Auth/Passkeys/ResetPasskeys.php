<?php

declare(strict_types=1);

namespace App\Actions\Auth\Passkeys;

use App\Mail\PasskeysResetMail;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Passkeys\Passkey;

/**
 * "Återställ passkeys" (ADR-029): a member who has lost every device gets
 * their passkeys removed by an owner or administrator of a company they
 * belong to, and registers a new one at their next sign-in.
 *
 * Passkeys belong to the user, not the company, so the reset reaches past
 * the company. It is therefore guarded: the one resetting must manage
 * members of a company the member belongs to, must themselves have signed in
 * with a passkey, may not reset themselves, and an administrator may not
 * reset the company's owner. The member's sessions end, the reset is written
 * to that company's audit log, and the member is emailed.
 */
class ResetPasskeys
{
    public static function allowed(User $by, Company $company, User $member, bool $byConfirmedWithPasskey): bool
    {
        return $by->id !== $member->id
            && $byConfirmedWithPasskey
            && $by->hasTeamPermission($company, 'members:manage')
            && ($company->user_id === $member->id || $company->users->contains('id', $member->id))
            && ! ($member->ownsTeam($company) && ! $by->ownsTeam($company));
    }

    /**
     * @return int the number of passkeys removed
     */
    public function __invoke(User $by, Company $company, User $member, bool $byConfirmedWithPasskey): int
    {
        if (! self::allowed($by, $company, $member, $byConfirmedWithPasskey)) {
            throw new AuthorizationException(__('Du kan inte återställa passkeys för den här personen.'));
        }

        $removed = DB::transaction(function () use ($by, $company, $member): int {
            $count = Passkey::query()->where('user_id', $member->id)->delete();

            $member->forceFill(['sessions_valid_from' => CarbonImmutable::now()])->save();

            Audit::record('passkey.reset', $member, ['member' => $member->name, 'removed' => $count], $company, $by);

            return $count;
        });

        Mail::to($member->email)->send(new PasskeysResetMail($member->name, $by->name, $company->name));

        return $removed;
    }
}
