<?php

declare(strict_types=1);

namespace App\Modules\Banking\Console;

use App\Models\Company;
use App\Modules\Banking\BankingModule;
use App\Modules\Banking\Domain\ConnectionStatus;
use App\Modules\Banking\Jobs\FetchBankDataJob;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Runs every hour and queues a fetch for each active connection that is
 * due by its company's setting (1–4 fetches a day; PSD2 lets a bank cap
 * fetches without the user present at four a day).
 */
class FetchBankDataCommand extends Command
{
    protected $signature = 'bank:fetch';

    protected $description = 'Hämtar transaktioner och saldon från anslutna banker (NONOX Bank)';

    public function handle(ModuleManager $modules): int
    {
        $queued = 0;

        foreach ($modules->companiesWith(BankingModule::KEY) as $companyId) {
            $company = Company::query()->find($companyId);

            if (! $company instanceof Company || $company->isArchived()) {
                continue;
            }

            $perDay = (int) BankingModule::setting($modules->settings(BankingModule::KEY, $company), 'fetches_per_day');
            $dueBefore = CarbonImmutable::now()->subMinutes(intdiv(24 * 60, max(1, $perDay)) - 5);

            $connections = CurrentCompany::run($company, fn () => BankConnection::query()
                ->where('status', ConnectionStatus::Active->value)
                ->where(fn ($query) => $query->whereNull('last_fetched_at')->orWhere('last_fetched_at', '<=', $dueBefore))
                ->pluck('id'));

            foreach ($connections as $connectionId) {
                $this->info("Köar hämtning för {$company->name}...");
                FetchBankDataJob::dispatch($company->id, (int) $connectionId);
                $queued++;
            }
        }

        $this->comment("Köade {$queued} hämtningar.");

        return self::SUCCESS;
    }
}
