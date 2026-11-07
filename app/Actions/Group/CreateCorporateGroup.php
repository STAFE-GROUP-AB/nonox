<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupAuditTrail;
use App\Models\Company;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupMember;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a shared group with the company as its parent (ADR-060, addendum
 * 2026-11-07, 2.1). Only an owner or administrator of the parent; one group
 * per parent. The parent becomes the first member, confirmed by the creator.
 */
final class CreateCorporateGroup
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $parent, string $name): CorporateGroup
    {
        $access = GroupAccess::for($user);

        if (! $access->isMember($parent->id)) {
            throw (new ModelNotFoundException)->setModel(Company::class, [$parent->id]);
        }

        if (! $access->mayAdminister($parent->id)) {
            throw new AuthorizationException(__('Bara ägare och administratörer kan skapa en koncern.'));
        }

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => __('Ge koncernen ett namn på högst 100 tecken.')]);
        }

        return DB::transaction(function () use ($user, $parent, $name): CorporateGroup {
            // The parent row is locked so two simultaneous requests cannot both create a group.
            Company::query()->whereKey($parent->id)->lockForUpdate()->first();

            if (CorporateGroup::query()->where('parent_company_id', $parent->id)->exists()) {
                throw ValidationException::withMessages(['name' => __('Företaget är redan moderföretag i en koncern.')]);
            }

            $group = CorporateGroup::create(['parent_company_id' => $parent->id, 'name' => $name, 'created_by' => $user->id]);

            CorporateGroupMember::create([
                'corporate_group_id' => $group->id,
                'company_id' => $parent->id,
                'holding_id' => null,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
            ]);

            GroupAuditTrail::record('corporate_group.created', $group, [$parent->id], $user, ['members' => 1], $parent->id);

            return $group;
        });
    }
}
