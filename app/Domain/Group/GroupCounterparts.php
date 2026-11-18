<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Models\CompanyHolding;
use App\Models\CorporateGroupMember;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;

/**
 * Which companies are in the same corporate group as a company on a day
 * (ADR-081 E27): both are confirmed members of one group (the parent has a
 * member row without a holding), and every holding that grounds a
 * subsidiary's membership is active that day (acquired on or before it, not
 * disposed of on or before it). A membership that has ended, or not begun,
 * counts as no group at all.
 *
 * Used to decide where an intra-group invoice may be delivered and where a
 * paired draft may be made. Nothing here is shown to a user: the answer only
 * allows or refuses, and a refusal never says why.
 */
final class GroupCounterparts
{
    /**
     * Every other company sharing an active group with the company on the day,
     * with the id of the (lowest) group they share. Three queries, whatever
     * the number of groups or members.
     *
     * @return array<int, int> company id => corporate group id
     */
    public static function of(int $companyId, CarbonImmutable $day): array
    {
        $groupIds = CorporateGroupMember::query()->where('company_id', $companyId)->pluck('corporate_group_id')->all();

        if ($groupIds === []) {
            return [];
        }

        $members = CorporateGroupMember::query()->whereIn('corporate_group_id', $groupIds)
            ->orderBy('corporate_group_id')
            ->get(['corporate_group_id', 'company_id', 'holding_id']);
        $holdingIds = $members->pluck('holding_id')->filter()->unique()->values()->all();
        $date = $day->toDateString();

        // Only the dates of the holdings that ground these memberships are read, to decide which
        // memberships are active today; nothing of a holding is shown or returned (ADR-081 E27).
        $active = $holdingIds === [] ? [] : CompanyScope::bypass(fn (): array => CompanyHolding::query()
            ->whereIn('id', $holdingIds)
            ->where('acquired_on', '<=', $date)
            ->where(fn ($query) => $query->whereNull('disposed_on')->orWhere('disposed_on', '>', $date))
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

        $activeIn = [];

        foreach ($members as $member) {
            if ($member->holding_id === null || in_array($member->holding_id, $active, true)) {
                $activeIn[$member->corporate_group_id][] = $member->company_id;
            }
        }

        $counterparts = [];

        foreach ($activeIn as $groupId => $companyIds) {
            if (! in_array($companyId, $companyIds, true)) {
                continue;
            }

            foreach ($companyIds as $other) {
                if ($other !== $companyId && ! isset($counterparts[$other])) {
                    $counterparts[$other] = (int) $groupId;
                }
            }
        }

        return $counterparts;
    }

    /** The group both companies are active members of on the day, or null. */
    public static function shared(int $companyId, int $otherId, CarbonImmutable $day): ?int
    {
        return self::of($companyId, $day)[$otherId] ?? null;
    }
}
