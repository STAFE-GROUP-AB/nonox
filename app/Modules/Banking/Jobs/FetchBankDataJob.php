<?php

declare(strict_types=1);

namespace App\Modules\Banking\Jobs;

use App\Models\Company;
use App\Modules\Banking\Actions\FetchBankData;
use App\Modules\Banking\BankingModule;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\RunsForCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A scheduled fetch of one connection, with the company given explicitly
 * (AGENTS.md §6.0). It checks the module in handle(), so a company that
 * turned the module off after the job was queued is not fetched. When the
 * provider may answer later (down, time-out, 429) the job is released for
 * the connection's pause (FetchBankData::BACKOFF_MINUTES) and tried at most
 * three times; fetching is idempotent (fingerprints), so a retry never
 * stores a transaction twice. A paused provider is not asked at all.
 */
class FetchBankDataJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsForCompany;

    public int $tries = 3;

    public function __construct(public int $companyId, public int $connectionId) {}

    public function handle(FetchBankData $fetch, ModuleManager $modules): void
    {
        CurrentCompany::set($this->companyId);

        if (! $modules->isEnabled(BankingModule::KEY, $this->companyId)) {
            return;
        }

        $company = Company::query()->find($this->companyId);
        $connection = BankConnection::query()->find($this->connectionId);

        if (! $company instanceof Company || $company->isArchived() || ! $connection instanceof BankConnection) {
            return;
        }

        $result = $fetch($company, $connection);

        if ($result->retryable && $this->attempts() < $this->tries) {
            $this->release(60 * FetchBankData::backoffMinutes((int) $connection->fresh()?->consecutive_failures));
        }
    }
}
