<?php

declare(strict_types=1);

namespace App\Actions\Units;

use App\Models\Article;
use App\Models\Company;
use App\Models\Unit;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Archives a unit so it is no longer offered, or brings it back (ADR-071
 * §12.1). A unit is never deleted: articles and saved rows keep pointing
 * at it. Needs the same permission as articles.
 */
class SetUnitArchived
{
    public function __invoke(User $user, Company $company, int $unitId, bool $archived): Unit
    {
        Gate::forUser($user)->authorize('create', [Article::class, $company]);

        return DB::transaction(fn (): Unit => CurrentCompany::run($company, function () use ($user, $company, $unitId, $archived): Unit {
            $unit = Unit::query()->findOrFail($unitId);
            $unit->update(['archived_at' => $archived ? now() : null]);
            Audit::record($archived ? 'unit.archived' : 'unit.restored', $unit, ['code' => $unit->code], $company, $user);

            return $unit;
        }));
    }
}
