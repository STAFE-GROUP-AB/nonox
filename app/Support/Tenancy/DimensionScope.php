<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Domain\Accounting\VoucherSource;
use App\Domain\Company\CompanyRole;
use App\Domain\Company\ScopeDimension;
use App\Models\Company;
use App\Models\MembershipDimensionScope;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Dimension scopes (ADR-081 gap 10): what a member limited to cost centres and
 * projects may reach inside the company. A scope only ever narrows a role; it
 * never widens one.
 *
 * Three layers keep a limited member inside the scope, and a leak is a P0 like
 * a cross-company one (AGENTS.md §1.3):
 *
 * 1. Permissions: a limited member keeps only SCOPE_AWARE_PERMISSIONS
 *    (User::hasTeamPermission, Memberships::may). Every other permission,
 *    module permissions included, is refused.
 * 2. Routes and Livewire components: only those DimensionScopeRegistry names
 *    as scoped or neutral answer; everything else answers 404
 *    (EnforceDimensionScope, LivewireDimensionScopeGuard).
 * 3. Data: the scoped pages filter their records here. A voucher or supplier
 *    invoice is visible only when every row with a cost centre or project is
 *    inside the scope and at least one row is (allowsRows, constrain).
 *
 * A row is inside the scope when its cost centre is one of the member's cost
 * centres or its project one of the member's projects. Rows without a cost
 * centre and project (bank, VAT, supplier debt) neither include nor exclude.
 *
 * Payroll vouchers (source PAYROLL) and their corrections are never shown
 * to a limited member, in lists, voucher pages or reports: a unit's salary
 * cost can be one person's pay. Until the analysis view (ADR-081 E17) has
 * its rule that hides cells under three people (owner decision 17), they
 * stay out entirely.
 * TODO(legal-verify): when aggregated pay per cost centre may be opened to a
 * unit manager through E17, and whether staff then need art. 13 information.
 *
 * Owner, admin, accountant and auditor are never limited (owner decision 22):
 * they answer for the whole ledger, and the auditor needs it by law. A unit
 * manager is always limited: without rows, they reach nothing. Others are
 * limited when their membership row says so (team_user.dimension_scoped),
 * which comes with the membership Jetstream reads anyway, so an unlimited
 * member costs no query.
 */
final class DimensionScope
{
    /** TODO(legal-verify): ABL 9 kap. 3 § gives the auditor access to everything; whoever answers for the books needs it too. */
    public const array UNSCOPABLE_ROLES = [
        CompanyRole::Admin->value, CompanyRole::Accountant->value, CompanyRole::Auditor->value,
    ];

    /** Limited by the role itself, with or without rows. */
    public const array ALWAYS_SCOPED_ROLES = [CompanyRole::UnitManager->value];

    /**
     * The permissions a limited member keeps. Each one's pages apply the scope
     * (DimensionScopeRegistry::SCOPED). A later package that makes a page
     * scope-aware adds its permission here in the same change.
     */
    public const array SCOPE_AWARE_PERMISSIONS = [
        'reports:view',
        'vouchers:view', 'vouchers:create', 'vouchers:post',
        'supplier-invoices:create', 'supplier-invoices:approve',
        // The project results (ADR-081 E7) show only the member's own projects, without payroll; budgets only as those projects' lines there.
        'projects:view', 'budgets:view',
    ];

    /**
     * @param  list<int>  $costCenterIds
     * @param  list<int>  $projectIds
     */
    private function __construct(
        public readonly bool $restricted,
        public readonly array $costCenterIds = [],
        public readonly array $projectIds = [],
    ) {}

    public static function unrestricted(): self
    {
        return new self(false);
    }

    /** The scope of the signed-in user in the current company. */
    public static function current(): self
    {
        $user = Auth::user();
        $companyId = CurrentCompany::id();

        return $user instanceof User && $companyId !== null ? self::for($user, $companyId) : self::unrestricted();
    }

    public static function for(User $user, Company|int $company): self
    {
        $companyId = $company instanceof Company ? $company->id : $company;

        if (! self::limits($user, $companyId)) {
            return self::unrestricted();
        }

        $cache = app(DimensionScopeCache::class);
        $key = "{$user->id}:{$companyId}";

        return $cache->scopes[$key] ??= self::load($user, $companyId);
    }

    /**
     * Whether the user is limited in the company: decided by the role and the
     * membership's flag alone, without reading the scope rows.
     */
    public static function limits(User $user, Company|int $company): bool
    {
        // The owner is never limited; asked first, so an owner's check loads no memberships (as Jetstream's does not).
        if ($company instanceof Company && $user->ownsTeam($company)) {
            return false;
        }

        $companyId = $company instanceof Company ? $company->id : $company;

        // With only the id: the user's current company is usually loaded already and tells the same without the memberships.
        if (! $company instanceof Company && $user->relationLoaded('currentTeam') && $user->currentTeam instanceof Company
            && $user->currentTeam->id === $companyId && (int) $user->currentTeam->user_id === $user->id) {
            return false;
        }

        $team = $user->teams->firstWhere('id', $companyId);

        // The owner has no membership row (and is never limited); a non-member has no permissions to narrow.
        if (! $team instanceof Company || (int) $team->user_id === $user->id) {
            return false;
        }

        $membership = $team->getRelationValue('membership');

        return self::limitsMembership((string) $membership?->getAttribute('role'), (bool) $membership?->getAttribute('dimension_scoped'));
    }

    public static function limitsMembership(string $role, bool $flagged): bool
    {
        if ($role === '' || in_array($role, self::UNSCOPABLE_ROLES, true)) {
            return false;
        }

        return $flagged || in_array($role, self::ALWAYS_SCOPED_ROLES, true);
    }

    public static function canBeLimited(string $role): bool
    {
        return $role !== '' && ! in_array($role, self::UNSCOPABLE_ROLES, true);
    }

    /** Whether the scope takes this permission away (User::hasTeamPermission asks first). */
    public static function narrows(User $user, mixed $team, string $permission): bool
    {
        return $team instanceof Company
            && ! in_array($permission, self::SCOPE_AWARE_PERMISSIONS, true)
            && self::limits($user, $team);
    }

    /**
     * Narrows a query of team_user to memberships that are not limited, for
     * code that picks recipients by role without asking each user (the same
     * rule as limitsMembership, in SQL).
     */
    public static function whereUnlimited(Builder $memberships): Builder
    {
        return $memberships
            ->whereNotIn('team_user.role', self::ALWAYS_SCOPED_ROLES)
            ->where(fn (Builder $query) => $query->where('team_user.dimension_scoped', false)->orWhereIn('team_user.role', self::UNSCOPABLE_ROLES));
    }

    /** Forget what was read in this request, after a scope changed. */
    public static function forget(): void
    {
        app(DimensionScopeCache::class)->scopes = [];
    }

    public function allowsCostCenter(?int $id): bool
    {
        return ! $this->restricted || ($id !== null && in_array($id, $this->costCenterIds, true));
    }

    public function allowsProject(?int $id): bool
    {
        return ! $this->restricted || ($id !== null && in_array($id, $this->projectIds, true));
    }

    /** Whether one row with these dimensions is inside the scope. */
    public function allowsRow(?int $costCenterId, ?int $projectId): bool
    {
        if (! $this->restricted) {
            return true;
        }

        return ($costCenterId !== null && in_array($costCenterId, $this->costCenterIds, true))
            || ($projectId !== null && in_array($projectId, $this->projectIds, true));
    }

    /**
     * Whether a record with these rows is inside the scope: every row with a
     * cost centre or project is inside, and at least one is.
     *
     * @param  iterable<mixed>  $rows  arrays or objects with cost_center_id and project_id
     */
    public function allowsRows(iterable $rows): bool
    {
        if (! $this->restricted) {
            return true;
        }

        $inside = false;

        foreach ($rows as $row) {
            $costCenterId = self::id(data_get($row, 'cost_center_id'));
            $projectId = self::id(data_get($row, 'project_id'));

            if ($costCenterId === null && $projectId === null) {
                continue;
            }

            if (! $this->allowsRow($costCenterId, $projectId)) {
                return false;
            }

            $inside = true;
        }

        return $inside;
    }

    /**
     * Whether a voucher or supplier invoice is inside the scope, by its rows.
     * Reads the rows only for a limited member, and only when they are not
     * loaded yet.
     */
    public function allowsRecord(Model $record, string $rowsRelation = 'rows'): bool
    {
        if (! $this->restricted) {
            return true;
        }

        if ($record instanceof Voucher && self::isPayroll($record)) {
            return false;
        }

        if ($record->relationLoaded($rowsRelation)) {
            /** @var iterable<mixed> $rows */
            $rows = $record->getRelation($rowsRelation);

            return $this->allowsRows($rows);
        }

        $relation = $record->{$rowsRelation}();

        if (! $relation instanceof HasMany) {
            return false;
        }

        return $this->allowsRows($relation->toBase()->get(['cost_center_id', 'project_id']));
    }

    /**
     * Limits a query of records (vouchers, supplier invoices) to those whose
     * rows are inside the scope, the same rule as allowsRows, in SQL. Adds no
     * query; an unlimited scope leaves the query as it is.
     *
     * @template TModel of Model
     *
     * @param  EloquentBuilder<TModel>  $query
     * @return EloquentBuilder<TModel>
     */
    public function constrain(EloquentBuilder $query, string $rowsTable, string $foreignKey, string $parentKey): EloquentBuilder
    {
        if (! $this->restricted) {
            return $query;
        }

        $inside = function (Builder $rows) use ($rowsTable): void {
            $rows->where(fn (Builder $row) => $row->whereNotNull("{$rowsTable}.cost_center_id")->whereIn("{$rowsTable}.cost_center_id", $this->costCenterIds))
                ->orWhere(fn (Builder $row) => $row->whereNotNull("{$rowsTable}.project_id")->whereIn("{$rowsTable}.project_id", $this->projectIds));
        };

        return $query
            ->whereExists(fn (Builder $rows) => $rows->select(DB::raw(1))->from($rowsTable)
                ->whereColumn("{$rowsTable}.{$foreignKey}", $parentKey)
                ->where($inside))
            ->whereNotExists(fn (Builder $rows) => $rows->select(DB::raw(1))->from($rowsTable)
                ->whereColumn("{$rowsTable}.{$foreignKey}", $parentKey)
                ->where(fn (Builder $row) => $row->whereNotNull("{$rowsTable}.cost_center_id")->orWhereNotNull("{$rowsTable}.project_id"))
                ->whereNot($inside));
    }

    /**
     * constrain() for vouchers, without the payroll vouchers and their
     * corrections, which a limited member never sees.
     *
     * @param  EloquentBuilder<Voucher>  $query
     * @return EloquentBuilder<Voucher>
     */
    public function constrainVouchers(EloquentBuilder $query): EloquentBuilder
    {
        if (! $this->restricted) {
            return $query;
        }

        self::withoutPayroll($query);

        return $this->constrain($query, 'voucher_rows', 'voucher_id', 'vouchers.id');
    }

    /**
     * Leaves out payroll vouchers and corrections of them from a query that
     * reads the vouchers table (as `vouchers`).
     *
     * @param  EloquentBuilder<*>|Builder  $query
     */
    public static function withoutPayroll(EloquentBuilder|Builder $query): void
    {
        $query->where('vouchers.source', '<>', VoucherSource::Payroll->value)
            ->whereNotExists(fn (Builder $originals) => $originals->select(DB::raw(1))->from('vouchers AS payroll_originals')
                ->whereColumn('payroll_originals.id', 'vouchers.corrects_voucher_id')
                ->where('payroll_originals.source', VoucherSource::Payroll->value));
    }

    /** A payroll voucher, or the correction of one. */
    public static function isPayroll(Voucher $voucher): bool
    {
        if ($voucher->source === VoucherSource::Payroll) {
            return true;
        }

        return $voucher->corrects_voucher_id !== null
            && Voucher::query()->whereKey($voucher->corrects_voucher_id)->where('source', VoucherSource::Payroll->value)->exists();
    }

    /**
     * A stable description of the scope, for the Livewire access fingerprint:
     * a page kept open while the scope changes answers 404 (LivewireCompanyGuard).
     */
    public function fingerprint(): string
    {
        if (! $this->restricted) {
            return '';
        }

        return 'scoped:'.implode(',', $this->costCenterIds).'|'.implode(',', $this->projectIds);
    }

    private static function load(User $user, int $companyId): self
    {
        // The user's own membership in a company they belong to (limits() checked it); the company may not be the current one.
        $rows = CompanyScope::bypass(fn () => MembershipDimensionScope::query()
            ->join('team_user', 'team_user.id', '=', 'membership_dimension_scopes.team_user_id')
            ->where('membership_dimension_scopes.company_id', $companyId)
            ->where('membership_dimension_scopes.user_id', $user->id)
            ->where('team_user.team_id', $companyId)
            ->where('team_user.user_id', $user->id)
            ->orderBy('membership_dimension_scopes.object_id')
            ->get(['membership_dimension_scopes.dimension', 'membership_dimension_scopes.object_id']));

        $ids = fn (ScopeDimension $dimension): array => array_values(array_map('intval', $rows->where('dimension', $dimension)->pluck('object_id')->all()));

        return new self(true, $ids(ScopeDimension::CostCenter), $ids(ScopeDimension::Project));
    }

    private static function id(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
