<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Pipeline;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Archives a pipeline, or takes it back (ADR-043 stage 2). An archived
 * pipeline keeps its won and lost deals for the figures but takes no new
 * ones, so its open deals are moved or closed first. The company always
 * keeps one pipeline in use; if the default is archived, the next one
 * becomes the default.
 */
final class ArchivePipeline
{
    public function __invoke(User $user, Company $company, Pipeline $pipeline, bool $archive = true): Pipeline
    {
        Gate::forUser($user)->authorize('configure', $pipeline);

        return DB::transaction(fn (): Pipeline => CurrentCompany::run($company, function () use ($user, $company, $pipeline, $archive): Pipeline {
            // Locked against SaveDeal and MoveDeal putting a deal into it meanwhile (they lock it too).
            $pipeline = Pipeline::query()->whereKey($pipeline->id)->lockForUpdate()->firstOrFail();

            if ($archive) {
                if (Deal::query()->where('pipeline_id', $pipeline->id)->where('status', DealStatus::Open->value)->exists()) {
                    throw ValidationException::withMessages(['pipeline' => __('Säljtratten har öppna affärer. Flytta dem till en annan säljtratt eller avsluta dem först.')]);
                }

                if (! Pipeline::query()->active()->whereKeyNot($pipeline->id)->exists()) {
                    throw ValidationException::withMessages(['pipeline' => __('Företaget behöver minst en säljtratt som används.')]);
                }
            }

            $pipeline->forceFill(['archived_at' => $archive ? now() : null, 'is_default' => $archive ? false : $pipeline->is_default])->save();

            if ($archive && ! Pipeline::query()->active()->where('is_default', true)->exists()) {
                Pipeline::query()->active()->ordered()->first()?->forceFill(['is_default' => true])->save();
            }

            Audit::record($archive ? 'crm.pipeline_archived' : 'crm.pipeline_restored', $pipeline, ['pipeline_id' => $pipeline->id], $company, $user);

            return $pipeline;
        }));
    }
}
