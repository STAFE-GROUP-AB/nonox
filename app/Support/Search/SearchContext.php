<?php

declare(strict_types=1);

namespace App\Support\Search;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who searches, in which company. Permission and policy answers are kept for
 * the request, so that asking for every result costs nothing.
 */
final class SearchContext
{
    /** @var array<string, bool> */
    private array $permissions = [];

    /** @var array<string, bool> */
    private array $abilities = [];

    public function __construct(
        public readonly User $user,
        public readonly Company $company,
    ) {}

    public function can(string $permission): bool
    {
        return $this->permissions[$permission] ??= $this->user->hasTeamPermission($this->company, $permission);
    }

    /** A policy's company-wide ability, such as viewAny on Customer. */
    public function allows(string $ability, string $model): bool
    {
        return $this->abilities["{$ability}:{$model}"] ??= Gate::forUser($this->user)->allows($ability, [$model, $this->company]);
    }
}
