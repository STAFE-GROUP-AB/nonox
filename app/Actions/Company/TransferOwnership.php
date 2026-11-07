<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Domain\Company\CompanyRole;
use App\Domain\Group\GroupRoleBinding;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The owner hands the company over to another member (AGENTS.md §4,
 * ADR-024). The member becomes owner (teams.user_id); the previous owner
 * stays as Administratör. Only members with one of the company roles can
 * take over, not people who only use Min sida or the staff ledger, and not
 * an account that has been deleted.
 */
class TransferOwnership
{
    public const string NEW_ROLE_FOR_PREVIOUS_OWNER = 'admin';

    public function __invoke(User $owner, Company $company, int $newOwnerId): User
    {
        Gate::forUser($owner)->authorize('transferOwnership', $company);

        return DB::transaction(function () use ($owner, $company, $newOwnerId): User {
            /** @var Company $locked */
            $locked = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

            if ($locked->user_id !== $owner->id) {
                throw ValidationException::withMessages(['owner' => __('Bara ägaren kan överlåta företaget.')]);
            }

            $membership = DB::table('team_user')->where('team_id', $locked->id)->where('user_id', $newOwnerId)->first(['id', 'role']);
            $newOwner = User::query()->whereKey($newOwnerId)->whereNull('anonymised_at')->first();

            if ($membership === null || ! $newOwner instanceof User || $newOwner->id === $owner->id) {
                throw ValidationException::withMessages(['owner' => __('Välj någon som redan finns i företaget.')]);
            }

            if (CompanyRole::tryFrom((string) $membership->role) === null) {
                throw ValidationException::withMessages(['owner' => __('Den nya ägaren måste ha en roll i företaget, inte bara tillgång till Min sida eller personalliggaren.')]);
            }

            DB::table('team_user')->where('team_id', $locked->id)->where('user_id', $newOwner->id)->delete();
            $previousOwnerMembershipId = DB::table('team_user')->insertGetId([
                'team_id' => $locked->id,
                'user_id' => $owner->id,
                'role' => self::NEW_ROLE_FOR_PREVIOUS_OWNER,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $locked->forceFill(['user_id' => $newOwner->id])->save();

            // Group roles in the parent follow the memberships, so none is lost and none revives (ADR-060).
            GroupRoleBinding::ownershipTransferred($locked, $owner, $previousOwnerMembershipId, $newOwner, (int) $membership->id);
            $company->setRawAttributes($locked->getAttributes(), true);
            $company->unsetRelation('owner')->unsetRelation('users');

            Audit::record('company.ownership_transferred', $locked, [
                'from_user_id' => $owner->id,
                'to_user_id' => $newOwner->id,
                'previous_role_of_new_owner' => (string) $membership->role,
                'new_role_of_previous_owner' => self::NEW_ROLE_FOR_PREVIOUS_OWNER,
            ], $locked, $owner);

            return $newOwner;
        });
    }
}
