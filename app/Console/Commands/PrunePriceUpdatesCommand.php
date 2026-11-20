<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\PriceUpdateStatus;
use App\Models\PriceUpdateRun;
use App\Models\PriceUpdateRunLine;
use App\Support\Files\StoredFiles;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retention of mass price updates (ADR-082 §8, decision 10): an uploaded
 * price file is kept with its update for two years and then deleted, while
 * the update and its lines (the diff) stay. A preview that was never applied
 * is cleared after seven days: its lines and file go, the run row stays with
 * its counts as a trace. Price files are commercial data, not
 * räkenskapsinformation; see docs/gdpr/lagringstider.md.
 */
class PrunePriceUpdatesCommand extends Command
{
    public const int FILE_YEARS = 2;

    public const int PREVIEW_DAYS = 7;

    protected $signature = 'pricing:prune-updates';

    protected $description = 'Raderar prisfiler två år efter prisuppdateringen och ogenomförda förhandsgranskningar efter sju dagar.';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $files = 0;
        $previews = 0;

        // Housekeeping across all companies, in a command with no signed-in user: the scope would hide every row.
        CompanyScope::bypass(function () use ($now, &$files, &$previews): void {
            PriceUpdateRun::query()
                ->where('status', PriceUpdateStatus::Preview->value)
                ->where('created_at', '<', $now->subDays(self::PREVIEW_DAYS))
                ->whereNull('file_deleted_at')
                ->orderBy('id')
                ->each(function (PriceUpdateRun $run) use ($now, &$previews): void {
                    $this->info("Rensar förhandsgranskning {$run->id}...");
                    $this->clear($run, $now, true);
                    $previews++;
                });

            PriceUpdateRun::query()
                ->where('status', '<>', PriceUpdateStatus::Preview->value)
                ->whereNotNull('file_path')
                ->whereRaw('COALESCE(applied_at, created_at) < ?', [$now->subYears(self::FILE_YEARS)])
                ->orderBy('id')
                ->each(function (PriceUpdateRun $run) use ($now, &$files): void {
                    $this->info("Raderar prisfilen till uppdatering {$run->id}...");
                    $this->clear($run, $now, false);
                    $files++;
                });
        });

        $this->comment("Raderade {$files} prisfiler och rensade {$previews} förhandsgranskningar.");

        return self::SUCCESS;
    }

    private function clear(PriceUpdateRun $run, CarbonImmutable $now, bool $withLines): void
    {
        DB::transaction(function () use ($run, $now, $withLines): void {
            $path = $run->file_path;

            if ($withLines) {
                PriceUpdateRunLine::query()->where('price_update_run_id', $run->id)->delete();
            }

            $run->forceFill(['file_path' => null, 'file_deleted_at' => $now])->save();

            if ($path !== null && str_starts_with($path, 'price-updates/') && ! str_contains($path, '..')) {
                StoredFiles::deleteUnreferenced([$path]);
            }
        });
    }
}
