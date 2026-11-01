<?php

declare(strict_types=1);

namespace App\Modules\Connect\Webhooks;

use App\Support\Tenancy\RunsForCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One attempt to deliver one webhook event (ADR-032), in the company given
 * explicitly (AGENTS.md §6.0). Runs once: retries are not the queue's but
 * WebhookDeliveries' own schedule, picked up by connect:webhooks, so they
 * survive a lost job and work with every queue driver.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use RunsForCompany;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $deliveryId, public int $companyId) {}

    public function handle(WebhookDeliveries $deliveries): void
    {
        $deliveries->attempt($this->deliveryId);
    }
}
