<?php

declare(strict_types=1);

namespace App\Modules\Banking\Console;

use App\Models\Company;
use App\Modules\Banking\Actions\ExpireBankConsents;
use App\Modules\Banking\BankingModule;
use App\Modules\Banking\Domain\ConnectionStatus;
use App\Modules\Banking\Jobs\FetchBankDataJob;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\Banking\Support\BankDataProviders;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Runs every hour and queues a fetch for each active connection that is
 * due by its company's setting (1–4 fetches a day; PSD2 lets a bank cap
 * fetches without the user present at four a day). A consent past its end
 * is marked as expired first and not fetched; a connection whose provider
 * failed waits until next_attempt_at; nothing is queued while the operator
 * has paused the provider (NONOX_BANKING_PAUSED).
 */
class FetchBankDataCommand extends Command
{
    protected $signature = 'bank:fetch';

    protected $description = 'Hämtar transaktioner och saldon från anslutna banker (NONOX Bank)';

    public function handle(ModuleManager $modules, ExpireBankConsents $expire): int
    {
        $queued = 0;
        $paused = BankDataProviders::isPaused();

        if ($paused) {
            $this->warn('Bankkopplingen är pausad (NONOX_BANKING_PAUSED): inga hämtningar köas.');
        }

        foreach ($modules->companiesWith(BankingModule::KEY) as $companyId) {
            $company = Company::query()->find($companyId);

            if (! $company instanceof Company || $company->isArchived()) {
                continue;
            }

            $expired = $expire($company);

            if ($expired > 0) {
                $this->info("Markerade {$expired} medgivanden som utgångna för företag {$company->id}.");
            }

            if ($paused) {
                continue;
            }

            $perDay = (int) BankingModule::setting($modules->settings(BankingModule::KEY, $company), 'fetches_per_day');
            $dueBefore = CarbonImmutable::now()->subMinutes(intdiv(24 * 60, max(1, $perDay)) - 5);

            $connections = CurrentCompany::run($company, fn () => BankConnection::query()
                ->where('status', ConnectionStatus::Active->value)
                ->where(fn ($query) => $query->whereNull('last_fetched_at')->orWhere('last_fetched_at', '<=', $dueBefore))
                ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->pluck('id'));

            foreach ($connections as $connectionId) {
                $this->info("Köar hämtning för företag {$company->id}...");
                FetchBankDataJob::dispatch($company->id, (int) $connectionId);
                $queued++;
            }
        }

        $this->comment("Köade {$queued} hämtningar.");

        return self::SUCCESS;
    }
}
