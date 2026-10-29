<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\Dimension;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Creates a cost centre or project, or renames and (de)activates one. The
 * code of an existing object never changes, since voucher rows and SIE files
 * refer to it.
 */
class SaveDimensionObject
{
    /**
     * @param  array<string, mixed>  $input  code (new only), name, is_active (existing only)
     */
    public function __invoke(User $user, Company $company, Dimension $dimension, array $input, CostCenter|Project|null $object = null): CostCenter|Project
    {
        $model = $dimension->model();

        $object === null
            ? Gate::forUser($user)->authorize('create', [$model, $company])
            : Gate::forUser($user)->authorize('update', $object);

        $validated = Validator::make($input, [
            'code' => $object === null
                ? ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique((new $model)->getTable(), 'code')->where('company_id', $company->id)]
                : ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'code.unique' => __('Koden :code används redan.', ['code' => $input['code'] ?? '']),
            'code.regex' => __('Koden får bara innehålla bokstäver utan å, ä och ö, siffror, punkt, bindestreck och understreck.'),
        ], [
            'code' => __('kod'),
            'name' => __('namn'),
        ])->validate();

        return DB::transaction(fn (): CostCenter|Project => CurrentCompany::run($company, function () use ($validated, $object, $model, $dimension, $company, $user): CostCenter|Project {
            if ($object === null) {
                $created = $model::create([
                    'code' => (string) $validated['code'],
                    'name' => trim((string) $validated['name']),
                ]);

                Audit::record('dimension.created', $created, [
                    'dimension' => $dimension->value,
                    'code' => $created->code,
                    'name' => $created->name,
                ], $company, $user);

                return $created;
            }

            $before = ['name' => $object->name, 'is_active' => $object->is_active];

            $object->update([
                'name' => trim((string) $validated['name']),
                'is_active' => (bool) ($validated['is_active'] ?? $object->is_active),
            ]);

            Audit::record('dimension.updated', $object, [
                'dimension' => $dimension->value,
                'code' => $object->code,
                ...Audit::changes($before, ['name' => $object->name, 'is_active' => $object->is_active]),
            ], $company, $user);

            return $object;
        }));
    }
}
