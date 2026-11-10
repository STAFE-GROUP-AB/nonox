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
use Illuminate\Validation\ValidationException;

/**
 * Chooses the unit a new row without an article starts with, on invoices,
 * quotes, orders, recurring invoices, quote templates and work orders
 * (ADR-071 §12.9). One per company; an archived unit cannot be chosen.
 * Needs the same permission as articles.
 */
class SetRowDefaultUnit
{
    public function __invoke(User $user, Company $company, int $unitId): Unit
    {
        Gate::forUser($user)->authorize('create', [Article::class, $company]);

        return DB::transaction(fn (): Unit => CurrentCompany::run($company, function () use ($user, $company, $unitId): Unit {
            $unit = Unit::query()->lockForUpdate()->findOrFail($unitId);

            if ($unit->isArchived()) {
                throw ValidationException::withMessages(['unit' => __('Enheten :unit är arkiverad och kan inte väljas.', ['unit' => $unit->code])]);
            }

            Unit::query()->where('row_default', true)->whereKeyNot($unit->id)->update(['row_default' => false]);
            $unit->update(['row_default' => true]);
            Audit::record('unit.row_default_set', $unit, ['code' => $unit->code], $company, $user);

            return $unit;
        }));
    }
}
