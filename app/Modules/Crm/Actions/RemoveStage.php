<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\DealStageChange;
use App\Modules\Crm\Models\PipelineStage;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Removes a stage from a pipeline (ADR-043 stage 2). A stage with deals,
 * open or closed, is removed only by moving them to another stage of the
 * same pipeline first ($moveTo); each open deal's move is in its history.
 * The last stage of a pipeline stays.
 */
final class RemoveStage
{
    public function __invoke(User $user, Company $company, PipelineStage $stage, ?PipelineStage $moveTo = null): void
    {
        Gate::forUser($user)->authorize('configure', $stage);

        DB::transaction(fn () => CurrentCompany::run($company, function () use ($user, $company, $stage, $moveTo): void {
            if (PipelineStage::query()->where('pipeline_id', $stage->pipeline_id)->count() <= 1) {
                throw ValidationException::withMessages(['stage' => __('En säljtratt behöver minst ett steg.')]);
            }

            $deals = Deal::query()->where('stage_id', $stage->id)->lockForUpdate()->get();

            if ($deals->isNotEmpty() && $moveTo === null) {
                throw ValidationException::withMessages(['stage' => __('Steget har :count affärer. Välj vilket steg de ska flyttas till.', ['count' => $deals->count()])]);
            }

            if ($moveTo !== null && ($moveTo->pipeline_id !== $stage->pipeline_id || $moveTo->id === $stage->id)) {
                throw ValidationException::withMessages(['stage' => __('Välj ett annat steg i samma säljtratt.')]);
            }

            foreach ($deals as $deal) {
                if ($deal->isOpen()) {
                    DealStageChange::create(['deal_id' => $deal->id, 'from_stage_id' => $stage->id, 'to_stage_id' => $moveTo?->id, 'user_id' => $user->id, 'changed_at' => now()]);
                }

                $deal->forceFill(['stage_id' => $moveTo?->id])->save();
            }

            $stage->delete();

            Audit::record('crm.stage_removed', null, ['pipeline_id' => $stage->pipeline_id, 'stage_id' => $stage->id, 'moved_to' => $moveTo?->id, 'deals' => $deals->count()], $company, $user);
        }));
    }
}
