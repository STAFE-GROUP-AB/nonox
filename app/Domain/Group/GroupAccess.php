<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Domain\Firm\Memberships;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\CompanyGroupMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Who may see what in a group (ADR-060, AGENTS.md §1.3). A group belongs to
 * one user and lists company ids; whether a company's data may be shown is
 * decided here, every time, from the user's memberships: a member with
 * reports:view in THAT company. Ids from the client are never trusted, and
 * a company the user has left drops out at once.
 */
final class GroupAccess
{
    public const string PERMISSION = 'reports:view';

    private function __construct(public readonly User $user, private readonly Memberships $memberships) {}

    public static function for(User $user): self
    {
        return new self($user, Memberships::of($user));
    }

    /** @return Collection<int, CompanyGroup> */
    public function groups(): Collection
    {
        return CompanyGroup::query()->where('user_id', $this->user->id)->orderBy('name')->get();
    }

    /** Another user's group answers 404, like any record of another tenant. */
    public function group(int $id): CompanyGroup
    {
        return CompanyGroup::query()->where('user_id', $this->user->id)->findOrFail($id);
    }

    public function mayView(int $companyId): bool
    {
        return $this->memberships->may($companyId, self::PERMISSION);
    }

    /** @return list<int> */
    public function viewableIds(): array
    {
        return $this->memberships->where(self::PERMISSION);
    }

    /** @return SupportCollection<int, Company> companies the user may pick for a group, by name */
    public function pickable(): SupportCollection
    {
        return $this->memberships->companies->filter(fn (Company $company): bool => $this->mayView($company->id))->values();
    }

    /**
     * The members of the group the user may see, with their companies, and
     * how many were left out because the user has no access to them.
     *
     * @return array{members: SupportCollection<int, array{member: CompanyGroupMember, company: Company}>, hidden: int}
     */
    public function members(CompanyGroup $group): array
    {
        $members = $group->members()->get();
        $visible = collect();

        foreach ($members as $member) {
            $company = $this->memberships->companies->firstWhere('id', $member->company_id);

            if ($company instanceof Company && $this->mayView($company->id)) {
                $visible->push(['member' => $member, 'company' => $company]);
            }
        }

        return [
            'members' => $visible->sortBy(fn (array $row): string => mb_strtolower($row['company']->name))->values(),
            'hidden' => $members->count() - $visible->count(),
        ];
    }
}
