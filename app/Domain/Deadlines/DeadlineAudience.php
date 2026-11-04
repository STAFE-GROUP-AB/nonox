<?php

declare(strict_types=1);

namespace App\Domain\Deadlines;

use App\Models\Company;
use App\Models\User;
use Laravel\Jetstream\Jetstream;

/**
 * Who is reminded of a company's dates (ADR-051, stage 2): the owner and
 * the members who look after the company and its year (company:update or
 * fiscal-years:manage), the same people who tick the start plan and close
 * the year.
 */
final class DeadlineAudience
{
    /** @var list<string> */
    public const array PERMISSIONS = ['company:update', 'fiscal-years:manage'];

    public static function includes(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'company:update') || $user->hasTeamPermission($company, 'fiscal-years:manage');
    }

    /**
     * The owner and the members whose role has one of the permissions, read
     * from the roles (two queries), not from each user's companies.
     *
     * @return list<User>
     */
    public static function of(Company $company): array
    {
        $users = [];
        $owner = User::query()->find($company->user_id);

        if ($owner instanceof User) {
            $users[$owner->id] = $owner;
        }

        foreach ($company->users()->get() as $member) {
            if (! $member instanceof User) {
                continue;
            }

            $role = Jetstream::findRole((string) $member->getRelationValue('membership')?->getAttribute('role'));

            if ($role !== null && array_intersect(self::PERMISSIONS, $role->permissions) !== []) {
                $users[$member->id] = $member;
            }
        }

        return array_values(array_filter($users, fn (User $user): bool => $user->anonymised_at === null));
    }
}
