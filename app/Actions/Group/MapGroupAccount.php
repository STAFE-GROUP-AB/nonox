<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Domain\Group\GroupAccess;
use App\Models\Account;
use App\Models\CorporateGroup;
use App\Models\GroupAccountMap;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The group's account map (ADR-060, addendum 2026-11-07, 2.4; ADR-081 E24):
 * one member company's account goes to another group account in the
 * consolidation. Identity needs no row. Only whoever administers the group
 * (owner or administrator in every member and the parent) changes it, since
 * it changes what the consolidated figures show. The row is the member
 * company's own data, written inside that company and logged there.
 */
final class MapGroupAccount
{
    /**
     * @param  array<string, mixed>  $input  account and group_account (account numbers)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function save(User $user, int $groupId, int $companyId, array $input): GroupAccountMap
    {
        [$access, $group] = $this->administered($user, $groupId);

        // A company that is not in the group answers as if it did not exist.
        if (! in_array($companyId, $access->includedCompanyIds($group), true)) {
            throw (new ModelNotFoundException)->setModel(CorporateGroup::class, [$companyId]);
        }

        $data = Validator::make($input, [
            'account' => ['required', 'integer', 'between:1000,8999'],
            'group_account' => ['required', 'integer', 'between:1000,8999', 'different:account'],
        ], [
            'account.required' => __('Välj företagets konto.'),
            'account.*' => __('Företagets konto ska vara ett kontonummer mellan 1000 och 8999.'),
            'group_account.required' => __('Ange koncernkontot.'),
            'group_account.different' => __('Koncernkontot är samma som företagets konto. Det behövs ingen mappning för det.'),
            'group_account.*' => __('Koncernkontot ska vara ett kontonummer mellan 1000 och 8999.'),
        ])->validate();

        $account = (int) $data['account'];
        $groupAccount = (int) $data['group_account'];

        if (intdiv($account, 1000) !== intdiv($groupAccount, 1000)) {
            throw ValidationException::withMessages(['group_account' => __('Koncernkontot måste vara i samma kontoklass som företagets konto (samma första siffra).')]);
        }

        return CurrentCompany::run($companyId, function () use ($user, $group, $companyId, $account, $groupAccount): GroupAccountMap {
            if (! Account::query()->where('number', $account)->exists()) {
                throw ValidationException::withMessages(['account' => __('Företaget har inget konto :number.', ['number' => $account])]);
            }

            return DB::transaction(function () use ($user, $group, $companyId, $account, $groupAccount): GroupAccountMap {
                $map = GroupAccountMap::query()->updateOrCreate(
                    ['corporate_group_id' => $group->id, 'account_number' => $account],
                    ['company_id' => $companyId, 'group_account_number' => $groupAccount, 'created_by' => $user->id],
                );

                Audit::record('group_account_map.saved', $map, [
                    'corporate_group_id' => $group->id,
                    'account_number' => $account,
                    'group_account_number' => $groupAccount,
                ], $companyId, $user);

                return $map;
            });
        });
    }

    /**
     * @throws AuthorizationException
     */
    public function remove(User $user, int $groupId, int $mapId): void
    {
        [, $group] = $this->administered($user, $groupId);

        // Across the members on purpose, limited to this group: the user administers every company in it
        // (checked above), so any of its rows is theirs to remove. Another group's row answers 404.
        $map = CompanyScope::bypass(fn (): GroupAccountMap => GroupAccountMap::query()->where('corporate_group_id', $group->id)->findOrFail($mapId));

        CurrentCompany::run($map->company_id, function () use ($user, $group, $map): void {
            DB::transaction(function () use ($user, $group, $map): void {
                $map->delete();

                Audit::record('group_account_map.removed', $map, [
                    'corporate_group_id' => $group->id,
                    'account_number' => $map->account_number,
                    'group_account_number' => $map->group_account_number,
                ], $map->company_id, $user);
            });
        });
    }

    /**
     * @return array{0: GroupAccess, 1: CorporateGroup}
     *
     * @throws AuthorizationException
     */
    private function administered(User $user, int $groupId): array
    {
        $access = GroupAccess::for($user);
        $group = $access->corporateGroup($groupId);

        if (! $access->administers($group)) {
            throw new AuthorizationException(__('Bara den som är ägare eller administratör i alla företag i koncernen kan ändra kontomappningen.'));
        }

        return [$access, $group];
    }
}
