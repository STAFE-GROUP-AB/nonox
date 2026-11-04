<?php

declare(strict_types=1);

namespace App\Modules\Crm\Console;

use App\Models\Company;
use App\Modules\Crm\Calendar\CalendarProviders;
use App\Modules\Crm\Calendar\Jobs\DisconnectCalendar;
use App\Modules\Crm\Calendar\Jobs\SyncCalendarConnection;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\CalendarConnection;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

/**
 * Queues a sync round for every active connected calendar (ADR-049), every
 * 15 minutes, and ends the connections NONOX may no longer use: their
 * provider is no longer configured, the company is archived or has CRM off
 * (the tokens are deleted, and the grant revoked where possible). With no
 * provider configured and no connections, which is the default, it does
 * nothing.
 */
class CalendarSyncCommand extends Command
{
    protected $signature = 'crm:calendar-sync';

    protected $description = 'Sync CRM activities with connected Google and Microsoft calendars';

    public function handle(CalendarProviders $providers, ModuleManager $modules): int
    {
        // Across companies on purpose: each job sets its own company before it reads anything.
        $connections = CompanyScope::bypass(fn () => CalendarConnection::query()
            ->where('status', '!=', CalendarConnection::REVOKED)
            ->get(['id', 'company_id', 'provider', 'status', 'calendar_id']));
        $queued = 0;
        $ended = 0;

        foreach ($connections as $connection) {
            $company = Company::query()->find($connection->company_id);
            $configured = $providers->for($connection->provider)->isConfigured();

            // Tokens are not kept for a calendar NONOX may no longer use: the provider is no longer set up, or CRM is off.
            if (! $configured || ! $company instanceof Company || $company->isArchived() || ! $modules->isEnabled(CrmModule::KEY, $company)) {
                $this->info("Ending connection `{$connection->id}`...");
                DisconnectCalendar::dispatch($connection->company_id, $connection->id, $configured);
                $ended++;

                continue;
            }

            if ($connection->status !== CalendarConnection::ACTIVE || $connection->calendar_id === null) {
                continue;
            }

            $this->info("Queueing connection `{$connection->id}`...");
            SyncCalendarConnection::dispatch($connection->company_id, $connection->id);
            $queued++;
        }

        $this->comment("Queued {$queued} calendar connections, ended {$ended}.");

        return self::SUCCESS;
    }
}
