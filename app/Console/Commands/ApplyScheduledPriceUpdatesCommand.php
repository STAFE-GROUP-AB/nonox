<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Pricing\ApplyScheduledPriceUpdate;
use App\Domain\Pricing\PriceUpdateStatus;
use App\Models\Company;
use App\Models\PriceUpdateRun;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Writes the base prices of scheduled mass price updates whose date has come
 * (ADR-082 §8). Runs every night; safe to run again, since a written price is
 * marked and never written twice. Each company is handled in its own
 * CurrentCompany::run and transaction, so one failing company does not stop
 * the others.
 */
class ApplyScheduledPriceUpdatesCommand extends Command
{
    protected $signature = 'pricing:apply-scheduled';

    protected $description = 'Skriver grundpriser från prisuppdateringar vars datum har kommit.';

    public function handle(ApplyScheduledPriceUpdate $apply): int
    {
        $today = CarbonImmutable::today()->toDateString();

        // Housekeeping across all companies, in a command with no signed-in user: the scope would hide every row.
        $runs = CompanyScope::bypass(fn () => PriceUpdateRun::query()
            ->where('status', PriceUpdateStatus::Scheduled->value)
            ->whereHas('lines', fn ($query) => $query->where('status', 'READY')->where('valid_from', '<=', $today))
            ->orderBy('company_id')->orderBy('id')
            ->get(['id', 'company_id']));

        $companies = Company::query()->whereKey($runs->pluck('company_id')->unique()->all())->get()->keyBy('id');
        $written = 0;
        $failed = 0;

        foreach ($runs as $run) {
            $company = $companies->get($run->company_id);

            if ($company === null) {
                continue;
            }

            $this->info("Prisuppdatering {$run->id} hos företag {$company->id}...");

            try {
                $written += $apply($company, $run->id);
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
            }
        }

        $this->comment("Skrev {$written} grundpriser från {$runs->count()} prisuppdateringar. Misslyckade: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
