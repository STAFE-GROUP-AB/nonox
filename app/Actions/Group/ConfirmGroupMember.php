<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Domain\Company\OrganisationNumber;
use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupAuditTrail;
use App\Models\Company;
use App\Models\CompanyHolding;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupMember;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a company to a shared group (ADR-060, addendum 2026-11-07, 2.1). The
 * user must be owner or administrator of that company and of every company
 * already in the group, and a member of the group must hold shares in it
 * (a holding naming its organisation number; indirect subsidiaries follow
 * from holdings of members). Refused when the company reports in another
 * currency than the parent (S2-R10), or when the votes recorded for it
 * within the group add up to more than 100 %.
 */
final class ConfirmGroupMember
{
    public const int MAX_MEMBERS = 50;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, int $groupId, Company $company): CorporateGroupMember
    {
        $access = GroupAccess::for($user);
        $group = $access->corporateGroup($groupId);

        if (! $access->administers($group)) {
            throw new AuthorizationException(__('Bara den som är ägare eller administratör i alla företag i koncernen kan lägga till företag.'));
        }

        if (! $access->isMember($company->id)) {
            throw (new ModelNotFoundException)->setModel(Company::class, [$company->id]);
        }

        if (! $access->mayAdminister($company->id)) {
            throw new AuthorizationException(__('Bara ägare och administratörer kan lägga till sitt företag i en koncern.'));
        }

        return DB::transaction(function () use ($user, $group, $company): CorporateGroupMember {
            // One confirmation at a time per group, so the vote check sees every holding.
            CorporateGroup::query()->whereKey($group->id)->lockForUpdate()->first();
            $members = $group->members()->get();

            if ($members->contains('company_id', $company->id)) {
                throw ValidationException::withMessages(['company' => __('Företaget ingår redan i koncernen.')]);
            }

            if ($members->count() >= self::MAX_MEMBERS) {
                throw ValidationException::withMessages(['company' => __('En koncern kan ha högst :max företag.', ['max' => self::MAX_MEMBERS])]);
            }

            $parent = Company::query()->findOrFail($group->parent_company_id);

            if (strtoupper((string) $company->base_currency) !== strtoupper((string) $parent->base_currency)) {
                throw ValidationException::withMessages(['company' => __('Företaget redovisar i :currency och moderföretaget i :parent. Koncerner med företag i olika valutor stöds inte.', [
                    'currency' => $company->base_currency,
                    'parent' => $parent->base_currency,
                ])]);
            }

            $holding = $this->holding($company, $members);
            $member = CorporateGroupMember::create([
                'corporate_group_id' => $group->id,
                'company_id' => $company->id,
                'holding_id' => $holding->id,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
            ]);

            $affected = [...$members->pluck('company_id')->all(), $company->id];
            GroupAuditTrail::record('corporate_group.member_confirmed', $group, $affected, $user, ['members' => count($affected)], $company->id);

            return $member;
        });
    }

    /**
     * The holding within the group that makes the company a member: of the
     * active holdings naming it, one with a control basis first, then the
     * largest share of the votes.
     *
     * @param  Collection<int, CorporateGroupMember>  $members
     */
    private function holding(Company $company, Collection $members): CompanyHolding
    {
        $orgNumber = OrganisationNumber::tryFrom((string) $company->org_number)?->formatted();

        if ($orgNumber === null) {
            throw ValidationException::withMessages(['company' => __('Företaget saknar ett giltigt organisationsnummer.')]);
        }

        // The holdings of the group's own members only, every one of which the user administers (checked above).
        $holdings = CompanyScope::bypass(fn () => CompanyHolding::query()
            ->whereIn('company_id', $members->pluck('company_id')->all())
            ->where('held_org_number', $orgNumber)
            ->activeOn(CarbonImmutable::today())
            ->lockForUpdate()
            ->get());

        if ($holdings->isEmpty()) {
            throw ValidationException::withMessages(['company' => __('Inget företag i koncernen har ett registrerat innehav i :name. Registrera innehavet hos ägaren först.', ['name' => $company->name])]);
        }

        if ($holdings->sum('voting_bp') > 10_000) {
            throw ValidationException::withMessages(['company' => __('Innehaven i :name har tillsammans mer än 100 procent av rösterna. Rätta innehaven innan företaget läggs till.', ['name' => $company->name])]);
        }

        return $holdings
            ->sort(fn (CompanyHolding $a, CompanyHolding $b): int => [$b->control_basis->givesControl(), $b->voting_bp, $a->id] <=> [$a->control_basis->givesControl(), $a->voting_bp, $b->id])
            ->firstOrFail();
    }
}
