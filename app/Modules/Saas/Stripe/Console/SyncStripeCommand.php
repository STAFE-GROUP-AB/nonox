<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Console;

use App\Modules\Saas\Stripe\Jobs\SyncStripe;
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

/**
 * Queues a read of every active Stripe connection (ADR-080 §7.3), hourly, so
 * the figures are at most an hour old without a webhook. Each job checks the
 * module and the NONOX Connect switch itself.
 */
class SyncStripeCommand extends Command
{
    protected $signature = 'saas:stripe-sync';

    protected $description = 'Läser ändringar från de kopplade Stripe-kontona';

    public function handle(): int
    {
        // Across companies on purpose: the scheduler has no company; each job runs inside its own.
        $connections = CompanyScope::bypass(fn () => StripeConnection::query()
            ->where('status', StripeConnection::ACTIVE)
            ->orderBy('id')
            ->get(['id', 'company_id']));

        foreach ($connections as $connection) {
            $this->line("Köar Stripe-koppling {$connection->id}...");
            SyncStripe::dispatch($connection->company_id, $connection->id);
        }

        $this->comment("Klart: {$connections->count()} kopplingar.");

        return self::SUCCESS;
    }
}
