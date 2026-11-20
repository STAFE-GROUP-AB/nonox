<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\PriceUpdateLineStatus;
use App\Domain\Pricing\PriceUpdateStatus;
use App\Domain\Pricing\PriceUpdateTarget;
use App\Domain\Pricing\PriceUpdateWriter;
use App\Models\Company;
use App\Models\PriceUpdateRun;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writes the base prices of a scheduled mass price update whose date has
 * come (ADR-082 §8), for pricing:apply-scheduled. Idempotent: the run is
 * locked, only READY lines dated today or earlier are written, and each
 * becomes APPLIED (or SKIPPED when the base price changed in between), so a
 * second call the same day writes nothing. The run is APPLIED when no line
 * is left waiting.
 *
 * @return int the number of base prices written
 */
class ApplyScheduledPriceUpdate
{
    public function __invoke(Company $company, int $runId): int
    {
        return DB::transaction(fn (): int => CurrentCompany::run($company, function () use ($company, $runId): int {
            $run = PriceUpdateRun::query()->whereKey($runId)->lockForUpdate()->first();

            if ($run === null || $run->status !== PriceUpdateStatus::Scheduled) {
                return 0;
            }

            $today = CarbonImmutable::today()->toDateString();
            $due = $run->lines()
                ->where('target', PriceUpdateTarget::Base->value)
                ->where('status', PriceUpdateLineStatus::Ready->value)
                ->where('valid_from', '<=', $today)
                ->orderBy('position')
                ->get();

            $written = PriceUpdateWriter::applyBase($run, $due, null);
            $waiting = $run->lines()->where('status', PriceUpdateLineStatus::Ready->value)->count();
            $counts = $run->counts ?? [];
            $counts['applied'] = ($counts['applied'] ?? 0) + $written['applied'];
            $counts['skipped'] = ($counts['skipped'] ?? 0) + $written['skipped'];
            $counts['scheduled'] = $waiting;

            $run->update(['counts' => $counts, 'status' => $waiting === 0 ? PriceUpdateStatus::Applied : PriceUpdateStatus::Scheduled]);

            if ($due->isNotEmpty()) {
                Audit::record('price_update.scheduled_applied', $run, ['applied' => $written['applied'], 'skipped' => $written['skipped'], 'waiting' => $waiting], $company);
            }

            return $written['applied'];
        }));
    }
}
