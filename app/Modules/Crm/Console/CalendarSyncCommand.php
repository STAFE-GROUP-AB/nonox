<?php

declare(strict_types=1);

namespace App\Modules\Crm\Console;

use App\Modules\Crm\Calendar\CalendarProviders;
use App\Modules\Crm\Calendar\Jobs\SyncCalendarConnection;
use App\Modules\Crm\Models\CalendarConnection;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

/**
 * Queues a sync round for every active connected calendar (ADR-049), every
 * 15 minutes. Does nothing while no calendar provider is configured, which
 * is the default: the two-way sync is dormant until the owner has
 * registered the apps with Google and Microsoft.
 */
class CalendarSyncCommand extends Command
{
    protected $signature = 'crm:calendar-sync';

    protected $description = 'Sync CRM activities with connected Google and Microsoft calendars';

    public function handle(CalendarProviders $providers): int
    {
        if (! $providers->anyConfigured()) {
            $this->comment('No calendar provider is configured. Nothing to sync.');

            return self::SUCCESS;
        }

        $configured = array_map(fn ($provider): string => $provider->key()->value, $providers->configured());
        // Across companies on purpose: each job sets its own company before it reads anything.
        $connections = CompanyScope::bypass(fn () => CalendarConnection::query()
            ->where('status', CalendarConnection::ACTIVE)
            ->whereNotNull('calendar_id')
            ->whereIn('provider', $configured)
            ->get(['id', 'company_id']));

        foreach ($connections as $connection) {
            $this->info("Queueing connection `{$connection->id}`...");
            SyncCalendarConnection::dispatch($connection->company_id, $connection->id);
        }

        $this->comment("Queued {$connections->count()} calendar connections.");

        return self::SUCCESS;
    }
}
