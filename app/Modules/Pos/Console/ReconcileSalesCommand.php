<?php

declare(strict_types=1);

namespace App\Modules\Pos\Console;

use App\Modules\ModuleManager;
use App\Modules\Pos\Actions\ReconcileSales;
use App\Modules\Pos\Domain\ConnectionStatus;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\PosModule;
use App\Modules\Pos\Support\PosApiFailed;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

/**
 * Fetches each connected till's sales for a period and records those not
 * yet here (ADR-042): the safety net under the webhook, and the only way
 * in for a till without one. Idempotent; --dry-run only counts.
 */
class ReconcileSalesCommand extends Command
{
    protected $signature = 'pos:reconcile-sales
        {--company= : Only this company id}
        {--since= : From this date (Y-m-d), default two days ago}
        {--until= : Up to and including this date (Y-m-d), default today}
        {--dry-run : Count only, write nothing}';

    protected $description = 'Fetch sales from connected tills and record the ones not yet recorded';

    public function handle(ModuleManager $modules, ReconcileSales $reconcile): int
    {
        $from = CarbonImmutable::parse((string) ($this->option('since') ?: CarbonImmutable::today('Europe/Stockholm')->subDays(2)->toDateString()), 'Europe/Stockholm')->startOfDay();
        $until = CarbonImmutable::parse((string) ($this->option('until') ?: CarbonImmutable::today('Europe/Stockholm')->toDateString()), 'Europe/Stockholm')->addDay()->startOfDay();
        $companies = $this->option('company') !== null ? [(int) $this->option('company')] : $modules->companiesWith(PosModule::KEY);
        $total = 0;

        foreach ($companies as $companyId) {
            if (! $modules->isEnabled(PosModule::KEY, $companyId)) {
                continue;
            }

            $connections = CurrentCompany::run($companyId, fn () => PosConnection::query()->where('status', ConnectionStatus::Active->value)->get());

            foreach ($connections as $connection) {
                $this->info("Fetching {$connection->provider->value} connection `{$connection->id}` of company `{$companyId}`...");

                try {
                    $result = $reconcile($connection, $from, $until, (bool) $this->option('dry-run'));
                } catch (PosApiFailed|ConnectionException $exception) {
                    CurrentCompany::run($companyId, function () use ($connection, $exception): void {
                        $connection->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 500)])->save();
                        $connection->log('error', __('Försäljningen kunde inte hämtas från kassan. NONOX försöker igen vid nästa hämtning.'));
                    });
                    $this->warn("  failed: {$exception->getMessage()}");

                    continue;
                }

                $this->line("  {$result['fetched']} sales, {$result['new']} new, {$result['known']} already recorded, {$result['fees']} fees.");
                $total += $result['new'];
            }
        }

        $this->comment($this->option('dry-run') ? "Dry run: {$total} sales would be recorded." : "Recorded {$total} new sales.");

        return self::SUCCESS;
    }
}
