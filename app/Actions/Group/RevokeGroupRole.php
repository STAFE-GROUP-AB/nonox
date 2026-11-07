<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupAuditTrail;
use App\Domain\Group\GroupRole;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes a role in a shared group away (ADR-060, addendum 2026-11-07, 2.2).
 * Whoever administers the group may revoke any role; a person may always
 * give up their own. Recorded in every member company's log.
 */
final class RevokeGroupRole
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, int $groupId, int $granteeId, string $role = 'viewer'): void
    {
        $access = GroupAccess::for($user);
        $groupRole = GroupRole::tryFrom($role);

        // Giving up one's own role works without seeing the group, for instance after leaving the parent.
        $ownRole = $granteeId === $user->id && $groupRole !== null
            && CorporateGroupRole::query()->where('corporate_group_id', $groupId)->where('user_id', $user->id)->where('role', $groupRole->value)->exists();

        $group = $ownRole ? CorporateGroup::query()->findOrFail($groupId) : $access->corporateGroup($groupId);

        if (! $ownRole && ! $access->administers($group)) {
            throw new AuthorizationException(__('Bara den som är ägare eller administratör i alla företag i koncernen kan ta bort roller.'));
        }

        if ($groupRole === null) {
            throw ValidationException::withMessages(['role' => __('Välj en roll.')]);
        }

        DB::transaction(function () use ($user, $group, $granteeId, $groupRole): void {
            CorporateGroup::query()->whereKey($group->id)->lockForUpdate()->first();

            $deleted = CorporateGroupRole::query()->where('corporate_group_id', $group->id)->where('user_id', $granteeId)->where('role', $groupRole->value)->delete();

            if ($deleted === 0) {
                throw ValidationException::withMessages(['user' => __('Personen har inte den rollen i koncernen.')]);
            }

            GroupAuditTrail::record('corporate_group.role_revoked', $group, $group->members()->pluck('company_id')->all(), $user, [
                'user_id' => $granteeId,
                'role' => $groupRole->value,
            ]);
        });
    }
}
