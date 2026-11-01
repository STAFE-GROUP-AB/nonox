<?php

declare(strict_types=1);

namespace App\Domain\Firm;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Collection;
use Laravel\Jetstream\Jetstream;

/**
 * The companies a user belongs to and the user's permissions in each, read
 * once (two queries for the memberships, one for the modules) so pages that
 * span companies never ask per company. Same rules as
 * User::hasTeamPermission.
 */
final class Memberships
{
    /** @var array<int, ?string> role per company, null for the owner */
    private array $roles = [];

    /** @var array<int, list<string>> enabled module keys per company */
    private array $modules = [];

    /** @param Collection<int, Company> $companies */
    private function __construct(public readonly User $user, public readonly Collection $companies)
    {
        foreach ($companies as $company) {
            $this->roles[$company->id] = $user->ownsTeam($company) ? null : (string) $company->getRelationValue('membership')?->getAttribute('role');
        }

        $ids = $this->ids();

        // The user's own companies only: the ids come from the memberships above.
        $this->modules = $ids === [] ? [] : CompanyScope::bypass(fn (): array => CompanyModule::query()->whereIn('company_id', $ids)->where('enabled', true)
            ->get(['company_id', 'module_key'])->groupBy('company_id')
            ->map(fn (Collection $rows): array => array_values($rows->pluck('module_key')->map(fn (mixed $key): string => (string) $key)->all()))->all());
    }

    public static function of(User $user): self
    {
        return new self($user, $user->companies());
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_values(array_map(fn (Company $company): int => $company->id, $this->companies->all()));
    }

    public function has(int $companyId): bool
    {
        return array_key_exists($companyId, $this->roles);
    }

    public function role(int $companyId): string
    {
        return $this->roles[$companyId] ?? 'owner';
    }

    public function enabled(int $companyId, string $module): bool
    {
        return in_array($module, $this->modules[$companyId] ?? [], true);
    }

    public function may(int $companyId, string $permission): bool
    {
        if (! $this->has($companyId)) {
            return false;
        }

        $module = app(ModuleRegistry::class)->moduleForPermission($permission);

        if ($module !== null && ! $this->enabled($companyId, $module->key())) {
            return false;
        }

        $role = $this->roles[$companyId];

        if ($role === null) {
            return $module === null || in_array($permission, $module->rolePermissions()['owner'] ?? [], true);
        }

        $permissions = isset(Jetstream::$roles[$role]) ? Jetstream::$roles[$role]->permissions : [];

        return in_array($permission, $permissions, true) || in_array('*', $permissions, true);
    }

    /**
     * The companies where the user holds a permission.
     *
     * @return list<int>
     */
    public function where(string $permission): array
    {
        return array_values(array_filter($this->ids(), fn (int $id): bool => $this->may($id, $permission)));
    }
}
