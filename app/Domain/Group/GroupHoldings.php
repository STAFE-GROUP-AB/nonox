<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Models\CompanyHolding;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The holdings of a group's members in one company, for the rule that the
 * votes recorded within a group may not exceed 100 % (ADR-060, addendum
 * 2026-11-07, 2.1). Used when a member is confirmed and whenever a holding
 * is recorded or changed, so both paths apply the same rule.
 */
final class GroupHoldings
{
    public const int ALL_VOTES_BP = 10_000;

    /**
     * Undisposed holdings (disposed_on empty or after today) that the
     * companies hold in the organisation number, locked for the transaction.
     *
     * @param  list<int>  $holderIds  the group's member companies
     * @return Collection<int, CompanyHolding>
     */
    public static function activeIn(array $holderIds, string $orgNumber): Collection
    {
        if ($holderIds === []) {
            return new Collection;
        }

        // Across the group's members on purpose: only shares and ids are summed, never shown,
        // and every caller has checked its own right to change the group or the holding first.
        return CompanyScope::bypass(fn (): Collection => CompanyHolding::query()
            ->whereIn('company_id', $holderIds)
            ->where('held_org_number', $orgNumber)
            ->activeOn(CarbonImmutable::today())
            ->orderBy('id')
            ->lockForUpdate()
            ->get());
    }
}
