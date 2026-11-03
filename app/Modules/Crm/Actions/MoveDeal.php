<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\DealStageChange;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Moves an open deal to another stage (ADR-043 stage 2): dragged on the
 * board, or with Flytta on the phone. The stage may be in another pipeline
 * that is not archived. Every move is a row in the deal's history, which
 * the sales cycle and the deal's card read, and an audit entry with ids
 * only.
 */
final class MoveDeal
{
    public function __invoke(User $user, Company $company, Deal $deal, PipelineStage|int $stage): Deal
    {
        Gate::forUser($user)->authorize('update', $deal);

        return DB::transaction(fn (): Deal => CurrentCompany::run($company, function () use ($user, $company, $deal, $stage): Deal {
            // Two people dragging the same card: the second sees where the first put it.
            $deal = Deal::query()->whereKey($deal->id)->lockForUpdate()->firstOrFail();
            $stage = $stage instanceof PipelineStage ? $stage : PipelineStage::query()->find($stage);

            if (! $deal->isOpen()) {
                throw ValidationException::withMessages(['stage_id' => __('Affären är avslutad och kan inte flyttas.')]);
            }

            if (! $stage instanceof PipelineStage) {
                throw ValidationException::withMessages(['stage_id' => __('Steget finns inte i säljtratten.')]);
            }

            if ($stage->pipeline_id !== $deal->pipeline_id && Pipeline::query()->whereKey($stage->pipeline_id)->whereNotNull('archived_at')->exists()) {
                throw ValidationException::withMessages(['stage_id' => __('Säljtratten är arkiverad och tar inte emot nya affärer.')]);
            }

            if ($stage->id === $deal->stage_id) {
                return $deal;
            }

            $from = $deal->stage_id;
            $deal->forceFill(['stage_id' => $stage->id, 'pipeline_id' => $stage->pipeline_id])->save();

            DealStageChange::create(['deal_id' => $deal->id, 'from_stage_id' => $from, 'to_stage_id' => $stage->id, 'user_id' => $user->id, 'changed_at' => now()]);
            Account::query()->whereKey($deal->account_id)->update(['last_activity_at' => now()]);
            Audit::record('crm.deal_moved', $deal, ['deal_id' => $deal->id, 'from_stage_id' => $from, 'to_stage_id' => $stage->id], $company, $user);

            return $deal;
        }));
    }
}
