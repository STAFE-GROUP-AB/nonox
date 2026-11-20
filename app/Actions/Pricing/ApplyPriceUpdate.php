<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\PriceUpdateLineStatus;
use App\Domain\Pricing\PriceUpdateStatus;
use App\Domain\Pricing\PriceUpdateTarget;
use App\Domain\Pricing\PriceUpdateWriter;
use App\Models\Company;
use App\Models\PriceUpdateRun;
use App\Models\PriceUpdateRunLine;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The second step of a mass price update (ADR-082 §8): writes the previewed
 * prices the user did not exclude, in one transaction. List prices get new
 * items from the line's date (the old ones end the day before); base prices
 * whose date is today are written now, later ones by pricing:apply-scheduled
 * on their date, so the run is APPLIED or SCHEDULED. A price that changed
 * after the preview is skipped, not overwritten, and counted.
 */
class ApplyPriceUpdate
{
    /** @param  list<int>  $excludedLineIds  lines the user took out of the preview */
    public function __invoke(User $user, Company $company, PriceUpdateRun $run, array $excludedLineIds = []): PriceUpdateRun
    {
        if ((int) $run->company_id !== (int) $company->id) {
            throw new ModelNotFoundException;
        }

        Gate::forUser($user)->authorize('update', $run);

        return DB::transaction(fn (): PriceUpdateRun => CurrentCompany::run($company, function () use ($user, $company, $run, $excludedLineIds): PriceUpdateRun {
            $run = PriceUpdateRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($run->status !== PriceUpdateStatus::Preview) {
                throw ValidationException::withMessages(['run' => __('Uppdateringen är redan genomförd eller återställd.')]);
            }

            $today = CarbonImmutable::today();
            $now = CarbonImmutable::now();

            if ($run->lines()->where('status', PriceUpdateLineStatus::Ready->value)->where('valid_from', '<', $today->toDateString())->exists()) {
                throw ValidationException::withMessages(['run' => __('Datumet i förhandsgranskningen har passerat. Gör en ny uppdatering med ett datum från i dag.')]);
            }

            if ($excludedLineIds !== []) {
                foreach (array_chunk(array_values(array_unique(array_map('intval', $excludedLineIds))), 1000) as $chunk) {
                    $run->lines()->whereKey($chunk)->where('status', PriceUpdateLineStatus::Ready->value)
                        ->update(['status' => PriceUpdateLineStatus::Excluded->value, 'updated_at' => $now]);
                }
            }

            $ready = $run->lines()->where('status', PriceUpdateLineStatus::Ready->value)->orderBy('position')->get();

            if ($ready->isEmpty()) {
                throw ValidationException::withMessages(['run' => __('Det finns inga priser kvar att ändra i uppdateringen.')]);
            }

            $list = PriceUpdateWriter::applyList($run, $ready->where('target', PriceUpdateTarget::List)->values());
            $base = PriceUpdateWriter::applyBase($run, $ready->filter(fn (PriceUpdateRunLine $line): bool => $line->target === PriceUpdateTarget::Base && $line->valid_from->lessThanOrEqualTo($today))->values(), $user->id);
            $waiting = $ready->filter(fn (PriceUpdateRunLine $line): bool => $line->target === PriceUpdateTarget::Base && $line->valid_from->greaterThan($today))->count();

            $counts = [
                ...($run->counts ?? []),
                'excluded' => $run->lines()->where('status', PriceUpdateLineStatus::Excluded->value)->count(),
                'applied' => $list['applied'] + $base['applied'],
                'skipped' => $list['skipped'] + $base['skipped'],
                'scheduled' => $waiting,
            ];

            // The run takes effect on the earliest price it wrote or will write, so the revert window follows what was applied.
            $effective = $run->lines()->whereIn('status', [PriceUpdateLineStatus::Applied->value, PriceUpdateLineStatus::Ready->value])->min('valid_from');

            $run->update([
                'effective_date' => $effective ?? $run->effective_date->toDateString(),
                'status' => $waiting > 0 ? PriceUpdateStatus::Scheduled : PriceUpdateStatus::Applied,
                'counts' => $counts,
                'applied_at' => $now,
            ]);

            Audit::record('price_update.applied', $run, ['status' => $run->status->value, 'counts' => $counts], $company, $user);

            return $run;
        }));
    }
}
