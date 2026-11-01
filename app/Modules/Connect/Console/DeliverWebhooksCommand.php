<?php

declare(strict_types=1);

namespace App\Modules\Connect\Console;

use App\Modules\Connect\Models\ConnectWebhookDelivery;
use App\Modules\Connect\Webhooks\DeliverWebhook;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

/**
 * Queues the webhook deliveries that are due (ADR-032): retries whose wait
 * is over, and any first attempt whose job was lost. Runs every minute.
 * Each job carries its own company id; the attempt itself claims the
 * delivery, so queuing one twice does no harm.
 */
final class DeliverWebhooksCommand extends Command
{
    protected $signature = 'connect:webhooks {--limit=500}';

    protected $description = 'Queue the webhook deliveries that are due';

    public function handle(): int
    {
        // Every company's due deliveries at once: each job is then run inside its own company.
        $due = CompanyScope::bypass(fn () => ConnectWebhookDelivery::query()
            ->where('status', ConnectWebhookDelivery::PENDING)
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['id', 'company_id']));

        $this->info("Queueing {$due->count()} webhook deliveries...");

        foreach ($due as $delivery) {
            DeliverWebhook::dispatch($delivery->id, $delivery->company_id);
        }

        $this->comment("Queued {$due->count()} webhook deliveries.");

        return self::SUCCESS;
    }
}
