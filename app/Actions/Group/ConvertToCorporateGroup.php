<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Domain\Group\GroupAccess;
use App\Models\Company;
use App\Models\CompanyGroupMember;
use App\Models\CorporateGroup;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * "Gör om till koncern" (ADR-060 decision D1, ADR-081 E22): turns one of the
 * user's own lists (the per-user groups of ADR-060 stage 1) into a shared
 * group with the chosen company of the list as its parent. Only the group is
 * made. The other companies of the list are not added: a company joins when
 * a holding in it is recorded and an owner or administrator of it confirms
 * (ConfirmGroupMember). The list is kept; it is retired later.
 */
final class ConvertToCorporateGroup
{
    public function __construct(private readonly CreateCorporateGroup $create) {}

    /**
     * @return array{group: CorporateGroup, pending: int} pending = companies of the list still to be added
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, int $listId, int $parentCompanyId): array
    {
        $access = GroupAccess::for($user);
        // Someone else's list answers 404.
        $list = $access->group($listId);
        $members = $list->members()->get();

        if (! $members->contains(fn (CompanyGroupMember $member): bool => $member->company_id === $parentCompanyId)) {
            throw ValidationException::withMessages(['parent' => __('Moderföretaget måste vara ett av företagen i listan.')]);
        }

        $parent = $access->companies()->firstWhere('id', $parentCompanyId);

        if (! $parent instanceof Company) {
            throw (new ModelNotFoundException)->setModel(Company::class, [$parentCompanyId]);
        }

        $group = ($this->create)($user, $parent, mb_substr($list->name, 0, 100));

        $pending = $members->filter(fn (CompanyGroupMember $member): bool => $member->company_id !== $parentCompanyId && $access->isMember($member->company_id))->count();

        return ['group' => $group, 'pending' => $pending];
    }
}
