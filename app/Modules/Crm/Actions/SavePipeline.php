<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Creates a pipeline, or renames one and changes its stages (ADR-043 stage
 * 2): `stages` is the list in order, each with a name and a probability in
 * whole percent; a stage with an `id` is renamed and moved, one without is
 * added. At least one and at most seven open stages. A stage left out of
 * the list is not removed here: RemoveStage moves its deals first.
 * `is_default` makes this the pipeline new deals go into.
 */
final class SavePipeline
{
    /**
     * @param  array<string, mixed>  $input  name, is_default, stages: list of {id?, name, probability}
     */
    public function __invoke(User $user, Company $company, array $input, ?Pipeline $pipeline = null): Pipeline
    {
        $pipeline === null
            ? Gate::forUser($user)->authorize('settings', [Pipeline::class, $company])
            : Gate::forUser($user)->authorize('configure', $pipeline);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'is_default' => ['sometimes', 'boolean'],
            'stages' => ['required', 'array', 'min:1', 'max:'.Pipeline::MAX_STAGES],
            'stages.*.id' => ['nullable', 'integer'],
            'stages.*.name' => ['required', 'string', 'max:100'],
            'stages.*.probability' => ['required', 'integer', 'between:0,100'],
        ], [
            'stages.max' => __('En säljtratt kan ha högst :max steg.', ['max' => Pipeline::MAX_STAGES]),
            'stages.min' => __('En säljtratt behöver minst ett steg.'),
            'stages.required' => __('En säljtratt behöver minst ett steg.'),
            'stages.*.probability.between' => __('Sannolikheten är en procentsats mellan 0 och 100.'),
        ], [
            'name' => __('namn'),
            'stages.*.name' => __('stegets namn'),
            'stages.*.probability' => __('sannolikhet'),
        ])->validate();

        return DB::transaction(fn (): Pipeline => CurrentCompany::run($company, function () use ($user, $company, $validated, $pipeline): Pipeline {
            $creating = $pipeline === null;
            $pipeline ??= new Pipeline(['sort' => (int) Pipeline::query()->max('sort') + 1]);
            $pipeline->fill(['name' => trim((string) $validated['name'])]);

            if ($pipeline->isArchived() && (bool) ($validated['is_default'] ?? false)) {
                throw ValidationException::withMessages(['is_default' => __('En arkiverad säljtratt kan inte vara förvald.')]);
            }

            $pipeline->save();

            /** @var list<array{id?: ?int, name: string, probability: int}> $stages */
            $stages = array_values($validated['stages']);
            $existing = PipelineStage::query()->where('pipeline_id', $pipeline->id)->get()->keyBy('id');
            $kept = array_filter(array_map(fn (array $stage): ?int => isset($stage['id']) ? (int) $stage['id'] : null, $stages));

            if (array_diff($kept, $existing->keys()->all()) !== []) {
                throw ValidationException::withMessages(['stages' => __('Steget finns inte i säljtratten.')]);
            }

            if ($existing->count() - count($kept) + count($stages) > Pipeline::MAX_STAGES) {
                throw ValidationException::withMessages(['stages' => __('En säljtratt kan ha högst :max steg. Ta bort ett steg innan du lägger till ett nytt.', ['max' => Pipeline::MAX_STAGES])]);
            }

            // Stages left out keep their place after the list until RemoveStage takes them away.
            foreach ($stages as $sort => $stage) {
                $model = isset($stage['id']) ? $existing->get((int) $stage['id']) : new PipelineStage(['pipeline_id' => $pipeline->id]);
                $model?->fill(['name' => trim($stage['name']), 'probability' => (int) $stage['probability'], 'sort' => $sort])->save();
            }

            $sort = count($stages);

            foreach ($existing->except($kept) as $left) {
                $left->forceFill(['sort' => $sort++])->save();
            }

            if ((bool) ($validated['is_default'] ?? false) || (! $pipeline->isArchived() && ! Pipeline::query()->where('is_default', true)->whereKeyNot($pipeline->id)->exists())) {
                Pipeline::query()->whereKeyNot($pipeline->id)->update(['is_default' => false]);
                $pipeline->forceFill(['is_default' => true])->save();
            }

            Audit::record($creating ? 'crm.pipeline_created' : 'crm.pipeline_updated', $pipeline, ['pipeline_id' => $pipeline->id, 'stages' => count($stages), 'is_default' => $pipeline->is_default], $company, $user);

            return $pipeline;
        }));
    }
}
