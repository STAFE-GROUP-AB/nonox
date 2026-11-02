<?php

declare(strict_types=1);

namespace App\Support\Hq;

use App\Domain\Accounting\VoucherStatus;
use App\Domain\Company\LegalForm;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WaitlistEntry;
use App\Modules\ModuleRegistry;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Laravel\Jetstream\Jetstream;

/**
 * HQ's read-only view of who uses the instance (ADR-038, "Kunder"): the
 * companies and the users, with metadata only. Never amounts, vouchers,
 * invoices, personal numbers or employee data: the only figure taken from
 * the bookkeeping is the number of posted vouchers.
 *
 * HQ is instance-level, not company-level, so every query here runs inside
 * CompanyScope::bypass (AGENTS.md §6.0). The callers are the HQ components,
 * which are only reachable by HQ admins (HqAccess).
 *
 * Every list costs the same number of queries whatever the number of rows:
 * the per-row facts are subqueries in the list query or one query per page.
 */
final class CustomerDirectory
{
    public const string SIGNED_IN = 'auth.signed_in';

    public const string SOURCE_WAITLIST = 'ko';

    public const string SOURCE_INVITATION = 'inbjudan';

    public const string SOURCE_OTHER = 'ovrigt';

    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * @param  array{search?: string, module?: string, archived?: string, from?: string, to?: string, sort?: string}  $filters
     * @return LengthAwarePaginator<int, Company>
     */
    public function companies(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        // HQ is instance-level (ADR-038): the list spans every company on purpose.
        return CompanyScope::bypass(function () use ($filters, $perPage): LengthAwarePaginator {
            $query = $this->withCompanyFacts(Company::query());

            $this->searchCompanies($query, $filters['search'] ?? '');

            $module = $filters['module'] ?? '';

            if ($module !== '' && $this->registry->has($module)) {
                $query->whereExists(fn (QueryBuilder $inner) => $inner->selectRaw('1')
                    ->from('company_modules')
                    ->whereColumn('company_modules.company_id', 'teams.id')
                    ->where('company_modules.module_key', $module)
                    ->where('company_modules.enabled', true));
            }

            match ($filters['archived'] ?? '') {
                'ja' => $query->whereNotNull('teams.archived_at'),
                'nej' => $query->whereNull('teams.archived_at'),
                default => null,
            };

            $this->createdBetween($query, 'teams.created_at', $filters['from'] ?? '', $filters['to'] ?? '');

            match ($filters['sort'] ?? '') {
                'aldst' => $query->orderBy('teams.created_at')->orderBy('teams.id'),
                'namn' => $query->orderByRaw('lower(teams.name)')->orderBy('teams.id'),
                'aktivitet' => $query->orderByRaw('last_activity_at desc nulls last')->orderByDesc('teams.id'),
                'verifikationer' => $query->orderByDesc('posted_vouchers_count')->orderByDesc('teams.id'),
                default => $query->orderByDesc('teams.created_at')->orderByDesc('teams.id'),
            };

            $page = $query->paginate($perPage);
            $this->attachModules($page->getCollection());

            return $page;
        });
    }

    public function company(int $id): ?Company
    {
        // HQ is instance-level (ADR-038): one company, whichever the admin has selected.
        return CompanyScope::bypass(function () use ($id): ?Company {
            $company = $this->withCompanyFacts(Company::query())->whereKey($id)->first();

            if ($company instanceof Company) {
                $this->attachModules(new Collection([$company]));
            }

            return $company;
        });
    }

    /**
     * The owner and the members of a company, with role and last sign-in.
     *
     * @return list<array{user: User, role: string, roleLabel: string, lastLogin: ?CarbonImmutable}>
     */
    public function members(Company $company): array
    {
        // HQ is instance-level (ADR-038): a company the admin need not be a member of.
        return CompanyScope::bypass(function () use ($company): array {
            $owner = User::query()->whereKey($company->user_id)->first(['id', 'name', 'email', 'anonymised_at', 'created_at']);
            $members = User::query()
                ->join('team_user', 'team_user.user_id', '=', 'users.id')
                ->where('team_user.team_id', $company->id)
                ->where('users.id', '!=', $company->user_id)
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'users.email', 'users.anonymised_at', 'users.created_at', 'team_user.role as membership_role']);
            $ids = [...$members->map(fn (User $member): int => $member->id)->all(), ...($owner instanceof User ? [$owner->id] : [])];
            $logins = $this->lastLogins($ids);
            $rows = [];

            if ($owner instanceof User) {
                $rows[] = ['user' => $owner, 'role' => 'owner', 'roleLabel' => $this->roleLabel('owner'), 'lastLogin' => $logins[$owner->id] ?? null];
            }

            foreach ($members as $member) {
                $role = $member->getAttribute('membership_role');
                $role = is_string($role) ? $role : '';
                $rows[] = ['user' => $member, 'role' => $role, 'roleLabel' => $this->roleLabel($role), 'lastLogin' => $logins[$member->id] ?? null];
            }

            return $rows;
        });
    }

    /**
     * Every module in the registry and whether it is on for the company.
     *
     * @return list<array{key: string, name: string, enabled: bool, enabledAt: ?CarbonImmutable}>
     */
    public function modulesOf(Company $company): array
    {
        // HQ is instance-level (ADR-038): the module rows of a company the admin need not be a member of.
        $states = CompanyScope::bypass(fn () => CompanyModule::query()->where('company_id', $company->id)->get()->keyBy('module_key'));
        $modules = [];

        foreach ($this->registry->all() as $module) {
            $state = $states->get($module->key());
            $modules[] = [
                'key' => $module->key(),
                'name' => $module->name(),
                'enabled' => $state instanceof CompanyModule && $state->enabled,
                'enabledAt' => $state instanceof CompanyModule && $state->enabled ? $state->enabled_at : null,
            ];
        }

        return $modules;
    }

    /**
     * @param  array{search?: string, source?: string, from?: string, to?: string, sort?: string}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function users(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        // HQ is instance-level (ADR-038): the users of every company.
        return CompanyScope::bypass(function () use ($filters, $perPage): LengthAwarePaginator {
            $query = $this->withUserFacts(User::query());

            $search = trim($filters['search'] ?? '');

            if ($search !== '') {
                $like = $this->like($search);
                $query->where(fn (Builder $inner) => $inner
                    ->whereRaw('lower(users.name) like ?', [$like])
                    ->orWhereRaw('lower(users.email) like ?', [$like]));
            }

            match ($filters['source'] ?? '') {
                self::SOURCE_WAITLIST => $query->whereExists($this->approvedFromWaitlist(...)),
                self::SOURCE_INVITATION => $query->whereNotExists($this->approvedFromWaitlist(...))->whereExists($this->memberOfACompany(...)),
                self::SOURCE_OTHER => $query->whereNotExists($this->approvedFromWaitlist(...))->whereNotExists($this->memberOfACompany(...)),
                default => null,
            };

            $this->createdBetween($query, 'users.created_at', $filters['from'] ?? '', $filters['to'] ?? '');

            match ($filters['sort'] ?? '') {
                'aldst' => $query->orderBy('users.created_at')->orderBy('users.id'),
                'namn' => $query->orderByRaw('lower(users.name)')->orderBy('users.id'),
                'inloggning' => $query->orderByRaw('last_login_at desc nulls last')->orderByDesc('users.id'),
                default => $query->orderByDesc('users.created_at')->orderByDesc('users.id'),
            };

            return $query->paginate($perPage);
        });
    }

    public function user(int $id): ?User
    {
        // HQ is instance-level (ADR-038): any user of the instance.
        return CompanyScope::bypass(fn (): ?User => $this->withUserFacts(User::query())->whereKey($id)->first());
    }

    /**
     * Where a user came from: approved from the waiting list, a member of a
     * company (since self-registration is closed, that means an invitation),
     * or anything else (the first-run setup, accounts from before the beta).
     */
    public static function sourceOf(User $user): string
    {
        if ((int) $user->getAttribute('waitlist_approvals_count') > 0) {
            return self::SOURCE_WAITLIST;
        }

        if ((bool) $user->getAttribute('teams_exists')) {
            return self::SOURCE_INVITATION;
        }

        return self::SOURCE_OTHER;
    }

    /** @return array<string, string> */
    public static function sources(): array
    {
        return [
            self::SOURCE_WAITLIST => __('Via kön'),
            self::SOURCE_INVITATION => __('Via inbjudan'),
            self::SOURCE_OTHER => __('Övrigt'),
        ];
    }

    /**
     * The companies of a user, owned ones first, with the role in each.
     *
     * @return list<array{company: Company, role: string, roleLabel: string}>
     */
    public function companiesOf(User $user): array
    {
        $rows = [];

        foreach ($user->ownedTeams as $company) {
            if ($company instanceof Company) {
                $rows[$company->id] = ['company' => $company, 'role' => 'owner', 'roleLabel' => $this->roleLabel('owner')];
            }
        }

        foreach ($user->teams as $company) {
            if (! $company instanceof Company || isset($rows[$company->id])) {
                continue;
            }

            $membership = $company->getRelation('membership');
            $role = $membership instanceof Model ? $membership->getAttribute('role') : null;
            $role = is_string($role) ? $role : '';
            $rows[$company->id] = ['company' => $company, 'role' => $role, 'roleLabel' => $this->roleLabel($role)];
        }

        return array_values($rows);
    }

    /** @return array<string, string> the modules a company list can be filtered on */
    public function moduleOptions(): array
    {
        $options = [];

        foreach ($this->registry->all() as $module) {
            $options[$module->key()] = $module->name();
        }

        return $options;
    }

    public function moduleName(string $key): string
    {
        return $this->registry->has($key) ? $this->registry->get($key)->name() : $key;
    }

    /**
     * The organisation number to show. A sole trader's (enskild firma) is
     * the owner's personal number, so HQ does not show it.
     */
    public static function displayedOrgNumber(Company $company): string
    {
        return $company->legal_form === LegalForm::Ef
            ? __('Dolt (personnummer)')
            : (string) $company->org_number;
    }

    public function roleLabel(string $role): string
    {
        if ($role === 'owner') {
            return __('Ägare');
        }

        return Jetstream::findRole($role)->name ?? $role;
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    private function withCompanyFacts(Builder $query): Builder
    {
        return $query
            ->select('teams.*')
            ->with('owner:id,name,email,anonymised_at')
            ->withCount('users')
            ->addSelect([
                'last_activity_at' => AuditLog::query()->selectRaw('max(audit_logs.created_at)')->whereColumn('audit_logs.company_id', 'teams.id'),
                'posted_vouchers_count' => Voucher::query()->selectRaw('count(*)')->whereColumn('vouchers.company_id', 'teams.id')->where('vouchers.status', VoucherStatus::Posted->value),
            ])
            ->withCasts(['last_activity_at' => 'immutable_datetime', 'posted_vouchers_count' => 'integer']);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function withUserFacts(Builder $query): Builder
    {
        return $query
            ->select('users.*')
            ->with(['teams:teams.id,teams.name,teams.legal_form,teams.archived_at', 'ownedTeams:id,user_id,name,legal_form,archived_at'])
            ->withExists('teams')
            ->addSelect([
                'last_login_at' => AuditLog::query()->selectRaw('max(audit_logs.created_at)')->whereColumn('audit_logs.user_id', 'users.id')->where('audit_logs.action', self::SIGNED_IN),
                'waitlist_approvals_count' => WaitlistEntry::query()->selectRaw('count(*)')->whereColumn('waitlist_entries.approved_user_id', 'users.id'),
            ])
            ->withCasts(['last_login_at' => 'immutable_datetime', 'waitlist_approvals_count' => 'integer']);
    }

    /** @param Builder<Company> $query */
    private function searchCompanies(Builder $query, string $search): void
    {
        $search = trim($search);

        if ($search === '') {
            return;
        }

        $digits = (string) preg_replace('/\D/', '', $search);
        $like = $this->like($search);

        $query->where(function (Builder $inner) use ($like, $digits): void {
            $inner->whereRaw('lower(teams.name) like ?', [$like])
                ->orWhereRaw('lower(coalesce(teams.legal_name, \'\')) like ?', [$like]);

            if (strlen($digits) >= 4) {
                $inner->orWhereRaw("replace(teams.org_number, '-', '') like ?", ['%'.$digits.'%']);
            }
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function createdBetween(Builder $query, string $column, string $from, string $to): void
    {
        $fromDate = self::date($from);
        $toDate = self::date($to);

        if ($fromDate !== null) {
            $query->where($column, '>=', $fromDate->startOfDay());
        }

        if ($toDate !== null) {
            $query->where($column, '<=', $toDate->endOfDay());
        }
    }

    public static function date(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** @param Collection<int, Company> $companies */
    private function attachModules(Collection $companies): void
    {
        $ids = $companies->pluck('id')->all();
        $enabled = $ids === [] ? collect() : CompanyModule::query()
            ->whereIn('company_id', $ids)
            ->where('enabled', true)
            ->orderBy('module_key')
            ->get(['company_id', 'module_key'])
            ->groupBy('company_id');

        foreach ($companies as $company) {
            $company->setAttribute('enabled_modules', $enabled->get($company->id, collect())->pluck('module_key')->map(fn (string $key): string => $this->moduleName($key))->values()->all());
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, CarbonImmutable>
     */
    private function lastLogins(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return AuditLog::query()
            ->where('action', self::SIGNED_IN)
            ->whereIn('user_id', $ids)
            ->groupBy('user_id')
            ->selectRaw('user_id, max(created_at) as last_login_at')
            ->get()
            ->mapWithKeys(fn (AuditLog $row): array => [(int) $row->user_id => CarbonImmutable::parse((string) $row->getAttribute('last_login_at'))])
            ->all();
    }

    private function approvedFromWaitlist(QueryBuilder $query): void
    {
        $query->selectRaw('1')->from('waitlist_entries')->whereColumn('waitlist_entries.approved_user_id', 'users.id');
    }

    private function memberOfACompany(QueryBuilder $query): void
    {
        $query->selectRaw('1')->from('team_user')->whereColumn('team_user.user_id', 'users.id');
    }

    private function like(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($search))).'%';
    }
}
