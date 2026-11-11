<?php

declare(strict_types=1);

namespace App\Actions\Units;

use App\Domain\Units\UnitCatalogue;
use App\Domain\Units\UnitDefaults;
use App\Domain\Units\UnitDimension;
use App\Models\Article;
use App\Models\Company;
use App\Models\Unit;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Adds a unit to the company's catalogue or changes one (ADR-071 §12.1).
 * A unit's code and dimension are fixed once it exists, because articles
 * print the code; its name and Peppol code can change. Needs the same
 * permission as articles.
 */
class SaveUnit
{
    /**
     * @param  array<string, mixed>  $input  code, name, dimension, rec20_code (optional)
     *
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, array $input, ?Unit $unit = null): Unit
    {
        Gate::forUser($user)->authorize('create', [Article::class, $company]);

        return DB::transaction(fn (): Unit => CurrentCompany::run($company, function () use ($user, $company, $input, $unit): Unit {
            UnitCatalogue::seed((int) $company->id);

            $rules = [
                'name' => ['required', 'string', 'max:60'],
                'rec20_code' => ['nullable', 'string', Rule::in(array_keys(UnitDefaults::REC20_CHOICES))],
            ];

            if ($unit === null) {
                $rules['code'] = ['required', 'string', 'max:10', 'regex:/^\S+$/u'];
                $rules['dimension'] = ['required', Rule::enum(UnitDimension::class)];
            }

            $validated = Validator::make($input, $rules, [
                'code.regex' => __('Enheten får inte innehålla mellanslag.'),
            ], [
                'code' => __('enhet'),
                'name' => __('namn'),
                'dimension' => __('mått'),
                'rec20_code' => __('Peppol-kod'),
            ])->validate();

            $rec20 = ($validated['rec20_code'] ?? '') === '' ? null : $validated['rec20_code'];

            if ($unit !== null) {
                $unit = Unit::query()->findOrFail($unit->id);
                $unit->update(['name' => trim($validated['name']), 'rec20_code' => $rec20]);
                Audit::record('unit.updated', $unit, ['code' => $unit->code], $company, $user);

                return $unit;
            }

            $code = trim($validated['code']);
            $taken = Unit::query()->get(['code', 'aliases'])->contains(fn (Unit $existing): bool => mb_strtolower($existing->code) === mb_strtolower($code)
                || in_array(mb_strtolower($code), $existing->aliases, true));

            if ($taken) {
                throw ValidationException::withMessages(['code' => __('Enheten :code finns redan.', ['code' => $code])]);
            }

            $unit = Unit::create([
                'code' => $code,
                'name' => trim($validated['name']),
                'dimension' => $validated['dimension'],
                'rec20_code' => $rec20,
                'aliases' => [],
                'is_default' => false,
            ]);
            Audit::record('unit.created', $unit, ['code' => $unit->code, 'dimension' => $unit->dimension->value], $company, $user);

            return $unit;
        }));
    }
}
