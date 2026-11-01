<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\ClientProfile;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Jetstream;

/**
 * Who a task can go to in a company, split into the accounting firm and the
 * client (ADR-028). The firm is every member with the Redovisningskonsult
 * role (ClientProfile::FIRM_ROLE), the same rule as the firm's note and the
 * firm activity panel; everyone else, the owner included, is the client.
 * A company without consultants has no firm side: all are "Kunden".
 */
final class TaskPeople
{
    public const string FIRM = 'firm';

    public const string CLIENT = 'client';

    /**
     * The owner and members, grouped by side, with their role. One query.
     *
     * @return array{firm: array<int, string>, client: array<int, string>}
     */
    public static function of(Company $company): array
    {
        $rows = DB::table('users')
            ->leftJoin('team_user', fn ($join) => $join->on('team_user.user_id', '=', 'users.id')->where('team_user.team_id', $company->id))
            ->where(fn ($query) => $query->where('users.id', $company->user_id)->orWhereNotNull('team_user.user_id'))
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'team_user.role']);
        $roles = collect(Jetstream::$roles)->mapWithKeys(fn ($role): array => [$role->key => $role->name])->all();
        $groups = [self::FIRM => [], self::CLIENT => []];

        foreach ($rows as $row) {
            $role = $row->role === null ? __('Ägare') : ($roles[$row->role] ?? (string) $row->role);
            $side = $row->role === ClientProfile::FIRM_ROLE ? self::FIRM : self::CLIENT;
            $groups[$side][(int) $row->id] = "{$row->name} ({$role})";
        }

        return $groups;
    }

    /** The side of one member, or null for someone outside the company. */
    public static function sideOf(Company $company, int $userId): ?string
    {
        if ($company->user_id === $userId) {
            return self::CLIENT;
        }

        $role = DB::table('team_user')->where('team_id', $company->id)->where('user_id', $userId)->value('role');

        if ($role === null) {
            return null;
        }

        return $role === ClientProfile::FIRM_ROLE ? self::FIRM : self::CLIENT;
    }

    /**
     * The sides of many people in many companies at once, for lists.
     *
     * @param  list<array{0: int, 1: int}>  $pairs  [company id, user id]
     * @return array<string, string> "company:user" => side
     */
    public static function sides(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $companies = array_values(array_unique(array_map(fn (array $pair): int => $pair[0], $pairs)));
        $users = array_values(array_unique(array_map(fn (array $pair): int => $pair[1], $pairs)));
        $firm = DB::table('team_user')->whereIn('team_id', $companies)->whereIn('user_id', $users)->where('role', ClientProfile::FIRM_ROLE)
            ->get(['team_id', 'user_id'])->map(fn (object $row): string => "{$row->team_id}:{$row->user_id}")->all();
        $sides = [];

        foreach ($pairs as [$company, $user]) {
            $sides["{$company}:{$user}"] = in_array("{$company}:{$user}", $firm, true) ? self::FIRM : self::CLIENT;
        }

        return $sides;
    }

    public static function label(string $side): string
    {
        return $side === self::FIRM ? __('Byrån') : __('Kunden');
    }
}
