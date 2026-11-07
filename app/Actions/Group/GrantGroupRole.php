<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupAuditTrail;
use App\Domain\Group\GroupRole;
use App\Models\Company;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives a person a role in a shared group (ADR-060, addendum 2026-11-07,
 * 2.2; decision D2: only `viewer` in stage 1). Only whoever administers the
 * group, and only for a member of the parent company. The role shows the
 * consolidated figures and nothing else; it is never a membership in a
 * company. Recorded in every member company's log.
 */
final class GrantGroupRole
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, int $groupId, int $granteeId, string $role = 'viewer'): CorporateGroupRole
    {
        $access = GroupAccess::for($user);
        $group = $access->corporateGroup($groupId);

        if (! $access->administers($group)) {
            throw new AuthorizationException(__('Bara den som är ägare eller administratör i alla företag i koncernen kan ge roller.'));
        }

        $groupRole = GroupRole::tryFrom($role);

        if ($groupRole === null) {
            throw ValidationException::withMessages(['role' => __('Välj en roll.')]);
        }

        $grantee = User::query()->find($granteeId);
        $parent = Company::query()->findOrFail($group->parent_company_id);

        // The same answer for an unknown person and one outside the parent: ids reveal nothing.
        if (! $grantee instanceof User || ! $parent->hasUser($grantee)) {
            throw ValidationException::withMessages(['user' => __('Personen måste vara medlem i moderföretaget.')]);
        }

        return DB::transaction(function () use ($user, $group, $grantee, $groupRole): CorporateGroupRole {
            CorporateGroup::query()->whereKey($group->id)->lockForUpdate()->first();

            $existing = CorporateGroupRole::query()->where('corporate_group_id', $group->id)->where('user_id', $grantee->id)->where('role', $groupRole->value)->first();

            if ($existing instanceof CorporateGroupRole) {
                return $existing;
            }

            $row = CorporateGroupRole::create([
                'corporate_group_id' => $group->id,
                'user_id' => $grantee->id,
                'role' => $groupRole->value,
                'granted_by' => $user->id,
                'granted_at' => now(),
            ]);

            GroupAuditTrail::record('corporate_group.role_granted', $group, $group->members()->pluck('company_id')->all(), $user, [
                'user_id' => $grantee->id,
                'role' => $groupRole->value,
            ]);

            return $row;
        });
    }
}
