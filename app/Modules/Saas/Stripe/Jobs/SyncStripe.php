<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Jobs;

use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\Saas\SaasModule;
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Modules\Saas\Stripe\Models\StripeSyncRun;
use App\Modules\Saas\Stripe\Support\StripeSync;
use App\Support\Connect\ConnectAddons;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\SetCompanyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Reads one Stripe connection (ADR-080 §7.3), with the company given
 * explicitly (AGENTS.md §6.0) and run inside it. At most one job per
 * connection waits, and two never run at once (WithoutOverlapping), so a
 * webhook arriving during a run queues the next one. It checks the module,
 * the NONOX Connect switch and the connection when it starts. A run Stripe
 * interrupted (429 or 5xx after the retries) is tried again later and goes
 * on from its stored cursor.
 */
class SyncStripe implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 900;

    public int $timeout = 1800;

    public function __construct(public int $companyId, public int $connectionId) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            new SetCompanyContext($this->companyId),
            (new WithoutOverlapping("stripe-sync-{$this->connectionId}"))->releaseAfter(60)->expireAfter(1800),
        ];
    }

    public function handle(StripeSync $sync, ModuleManager $modules): void
    {
        CurrentCompany::run($this->companyId, function () use ($sync, $modules): void {
            if (! $modules->isEnabled(SaasModule::KEY, $this->companyId) || ! ConnectAddons::isOn($this->companyId, ConnectAddons::STRIPE)) {
                return;
            }

            $company = Company::query()->find($this->companyId);
            $connection = StripeConnection::query()->find($this->connectionId);

            if (! $company instanceof Company || $company->isArchived() || ! $connection instanceof StripeConnection) {
                return;
            }

            $run = $sync->run($connection);

            if ($run?->status === StripeSyncRun::INTERRUPTED && $this->attempts() < $this->tries) {
                $this->release(300 * $this->attempts());
            }
        });
    }
}
