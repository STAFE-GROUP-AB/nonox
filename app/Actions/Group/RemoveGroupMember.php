<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupAuditTrail;
use App\Models\Company;
use App\Models\CompanyHolding;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupMember;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes a company out of a shared group (ADR-060, addendum 2026-11-07, 2.1).
 * Allowed for whoever administers the group, and always for an owner or
 * administrator of the company itself or of the member holding its shares:
 * consent can be withdrawn without seeing the rest of the group. The parent cannot be removed, and a
 * company through which other members are held is removed only after them.
 */
final class RemoveGroupMember
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, int $groupId, int $companyId): void
    {
        $access = GroupAccess::for($user);
        $group = CorporateGroup::query()->with('members')->find($groupId);
        $withdrawal = $group instanceof CorporateGroup && $this->withdrawal($access, $group, $companyId);

        if (! $group instanceof CorporateGroup || (! $withdrawal && ! $access->levelFor($group)->sees())) {
            throw (new ModelNotFoundException)->setModel(CorporateGroup::class, [$groupId]);
        }

        // An archived member freezes administration; only that member itself can still be taken out.
        $archived = Company::query()->whereKey($companyId)->whereNotNull('archived_at')->exists();

        if (! $withdrawal && ! $access->administers($group, $archived ? [$companyId] : [])) {
            throw new AuthorizationException(__('Bara den som är ägare eller administratör i alla företag i koncernen kan ta bort företag.'));
        }

        if ($companyId === $group->parent_company_id) {
            throw ValidationException::withMessages(['company' => __('Moderföretaget kan inte tas bort ur koncernen.')]);
        }

        DB::transaction(function () use ($user, $group, $companyId): void {
            CorporateGroup::query()->whereKey($group->id)->lockForUpdate()->first();
            $members = $group->members()->get();
            $member = $members->firstWhere('company_id', $companyId);

            if (! $member instanceof CorporateGroupMember) {
                throw ValidationException::withMessages(['company' => __('Företaget ingår inte i koncernen.')]);
            }

            $heldThrough = $members->pluck('holding_id')->filter()->values()->all();

            // Read inside the company: only its own holdings can match.
            if ($heldThrough !== [] && CurrentCompany::run($companyId, fn (): bool => CompanyHolding::query()->whereIn('id', $heldThrough)->exists())) {
                throw ValidationException::withMessages(['company' => __('Andra företag i koncernen ägs genom det här företaget. Ta bort dem först.')]);
            }

            $member->delete();

            GroupAuditTrail::record('corporate_group.member_removed', $group, $members->pluck('company_id')->all(), $user, ['members' => $members->count() - 1], $companyId);
        });
    }

    /**
     * Consent can be taken back, without seeing the group, by an owner or
     * administrator (archived or not) of the member itself, or of the member
     * that holds the shares the membership rests on.
     */
    private function withdrawal(GroupAccess $access, CorporateGroup $group, int $companyId): bool
    {
        $member = $group->members->firstWhere('company_id', $companyId);

        if (! $member instanceof CorporateGroupMember) {
            return false;
        }

        if ($access->mayWithdraw($companyId)) {
            return true;
        }

        if ($member->holding_id === null) {
            return false;
        }

        foreach ($group->members as $holder) {
            // Read inside the holder: only its own holding can match.
            if ($access->mayWithdraw($holder->company_id) && CurrentCompany::run($holder->company_id, fn (): bool => CompanyHolding::query()->whereKey($member->holding_id)->exists())) {
                return true;
            }
        }

        return false;
    }
}
