<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Domain\Group\GroupAccess;
use App\Domain\Group\MarkKind;
use App\Models\Account;
use App\Models\CompanyGroup;
use App\Models\CompanyGroupMark;
use App\Models\CompanyGroupMember;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and edits a user's groups (ADR-060). A group is the user's own, so
 * every method works on the user's groups only (another user's id answers
 * 404) and accepts only companies where the user holds reports:view. Group
 * settings are not bookkeeping and write no audit entry.
 */
class ManageCompanyGroup
{
    public const int MAX_GROUPS = 20;

    public const int MAX_COMPANIES = 50;

    public function create(User $user, string $name): CompanyGroup
    {
        $name = $this->name($user, $name, null);

        if (CompanyGroup::query()->where('user_id', $user->id)->count() >= self::MAX_GROUPS) {
            throw ValidationException::withMessages(['name' => __('Du kan ha högst :max koncerner.', ['max' => self::MAX_GROUPS])]);
        }

        return CompanyGroup::create(['user_id' => $user->id, 'name' => $name]);
    }

    public function rename(User $user, int $groupId, string $name): CompanyGroup
    {
        $group = GroupAccess::for($user)->group($groupId);
        $group->forceFill(['name' => $this->name($user, $name, $group)])->save();

        return $group;
    }

    public function delete(User $user, int $groupId): void
    {
        GroupAccess::for($user)->group($groupId)->delete();
    }

    /** @param  ?string  $share  ownership share in percent, "100" or "62,5"; empty for none */
    public function addCompany(User $user, int $groupId, int $companyId, ?string $share = null): CompanyGroupMember
    {
        $access = GroupAccess::for($user);
        $group = $access->group($groupId);

        if (! $access->mayView($companyId)) {
            throw ValidationException::withMessages(['company' => __('Du kan bara ta med företag där du får se rapporter.')]);
        }

        return DB::transaction(function () use ($group, $companyId, $share): CompanyGroupMember {
            if (CompanyGroupMember::query()->where('company_group_id', $group->id)->count() >= self::MAX_COMPANIES) {
                throw ValidationException::withMessages(['company' => __('En koncern kan ha högst :max företag.', ['max' => self::MAX_COMPANIES])]);
            }

            return CompanyGroupMember::query()->updateOrCreate(
                ['company_group_id' => $group->id, 'company_id' => $companyId],
                ['ownership_bp' => $this->share($share)],
            );
        });
    }

    public function setShare(User $user, int $groupId, int $companyId, ?string $share): void
    {
        $group = GroupAccess::for($user)->group($groupId);
        $bp = $this->share($share);

        CompanyGroupMember::query()->where('company_group_id', $group->id)->where('company_id', $companyId)->update(['ownership_bp' => $bp]);
    }

    /** Removing a company also removes its marks in the group. */
    public function removeCompany(User $user, int $groupId, int $companyId): void
    {
        $group = GroupAccess::for($user)->group($groupId);

        DB::transaction(function () use ($group, $companyId): void {
            CompanyGroupMember::query()->where('company_group_id', $group->id)->where('company_id', $companyId)->delete();
            CompanyGroupMark::query()->where('company_group_id', $group->id)->where('company_id', $companyId)->delete();
        });
    }

    public function mark(User $user, int $groupId, int $companyId, int $accountNumber, string $kind): CompanyGroupMark
    {
        $access = GroupAccess::for($user);
        $group = $access->group($groupId);
        $markKind = MarkKind::tryFrom($kind);

        if ($markKind === null) {
            throw ValidationException::withMessages(['kind' => __('Välj vad kontot innehåller.')]);
        }

        $this->assertMember($access, $group, $companyId);

        // Read inside the company, so the account must exist in its own chart of accounts.
        if (! CurrentCompany::run($companyId, fn (): bool => Account::query()->where('number', $accountNumber)->exists())) {
            throw ValidationException::withMessages(['account' => __('Kontot finns inte i företagets kontoplan.')]);
        }

        return CompanyGroupMark::query()->updateOrCreate(
            ['company_group_id' => $group->id, 'company_id' => $companyId, 'account_number' => $accountNumber],
            ['kind' => $markKind->value],
        );
    }

    public function unmark(User $user, int $groupId, int $markId): void
    {
        $group = GroupAccess::for($user)->group($groupId);

        CompanyGroupMark::query()->where('company_group_id', $group->id)->whereKey($markId)->delete();
    }

    /**
     * Marks every account whose name says "koncern" in the companies of the
     * group the user may see (BAS: Andelar i koncernföretag, Fordringar hos
     * koncernföretag, Skulder till koncernföretag), by its class. The user
     * can change or remove them. Returns how many were marked.
     */
    public function suggest(User $user, int $groupId): int
    {
        $access = GroupAccess::for($user);
        $group = $access->group($groupId);
        $count = 0;

        foreach ($access->members($group)['members'] as $row) {
            $accounts = CurrentCompany::run($row['company'], fn () => Account::query()->where('name', 'ilike', '%koncern%')->get(['number', 'name']));

            foreach ($accounts as $account) {
                $kind = MarkKind::suggest($account->number, $account->name);

                if ($kind === null) {
                    continue;
                }

                $mark = CompanyGroupMark::query()->firstOrCreate(
                    ['company_group_id' => $group->id, 'company_id' => $row['company']->id, 'account_number' => $account->number],
                    ['kind' => $kind->value],
                );
                $count += $mark->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $count;
    }

    private function assertMember(GroupAccess $access, CompanyGroup $group, int $companyId): void
    {
        $isMember = collect($access->members($group)['members'])->contains(fn (array $row): bool => $row['company']->id === $companyId);

        if (! $isMember) {
            throw ValidationException::withMessages(['company' => __('Företaget ingår inte i koncernen.')]);
        }
    }

    private function name(User $user, string $name, ?CompanyGroup $except): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => __('Ge koncernen ett namn på högst 100 tecken.')]);
        }

        $taken = CompanyGroup::query()->where('user_id', $user->id)->where('name', $name)->when($except, fn ($query) => $query->whereKeyNot($except?->id))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => __('Du har redan en koncern med det namnet.')]);
        }

        return $name;
    }

    private function share(?string $share): ?int
    {
        $share = trim((string) $share);

        if ($share === '') {
            return null;
        }

        $normal = str_replace([',', ' ', '%'], ['.', '', ''], $share);

        if (! is_numeric($normal) || (float) $normal < 0 || (float) $normal > 100) {
            throw ValidationException::withMessages(['share' => __('Ägarandelen ska vara mellan 0 och 100 procent.')]);
        }

        return (int) round((float) $normal * 100);
    }
}
