<?php

declare(strict_types=1);

namespace App\Modules\Connect\Webhooks;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\Mail\WebhookDisabledMail;
use App\Modules\Connect\Models\ConnectWebhook;
use App\Modules\Connect\Models\ConnectWebhookDelivery;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The delivery schedule of webhooks (ADR-032). An event is tried at once,
 * then after 1, 5 and 15 minutes, 1, 3, 6 and 13 hours: eight attempts over
 * about 23 hours. Anything but a 2xx answer within ten seconds is a failure.
 * When the last attempt fails, the webhook is turned off, its waiting events
 * are cancelled and the company's owner is told by e-mail; it is turned on
 * again under Anslutningar.
 *
 * An attempt claims its delivery with a conditional update (a lease), so
 * two workers never send the same attempt twice. Receivers must still
 * expect duplicates (a lost answer is retried) and use Nonox-Event-Id.
 * Every method must run inside the company.
 */
final class WebhookDeliveries
{
    public const int MAX_ATTEMPTS = 8;

    /** Seconds to wait after attempt 1, 2, … 7 failed. */
    public const array RETRY_DELAYS = [60, 300, 900, 3600, 10800, 21600, 46800];

    /** How long a claimed attempt is held before another worker may try it. */
    private const int LEASE_SECONDS = 120;

    public function __construct(private readonly WebhookSender $sender) {}

    public function attempt(string $deliveryId): void
    {
        $claimed = ConnectWebhookDelivery::query()
            ->whereKey($deliveryId)
            ->where('status', ConnectWebhookDelivery::PENDING)
            ->where('next_attempt_at', '<=', now())
            ->update(['next_attempt_at' => now()->addSeconds(self::LEASE_SECONDS), 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $delivery = ConnectWebhookDelivery::query()->with('webhook')->findOrFail($deliveryId);
        $webhook = $delivery->webhook;

        if (! $webhook instanceof ConnectWebhook || ! $webhook->isActive()) {
            $delivery->forceFill(['status' => ConnectWebhookDelivery::CANCELLED, 'next_attempt_at' => null])->save();

            return;
        }

        $result = $this->sender->send($webhook, $delivery->event_id, $delivery->event_type, $delivery->body);
        $attempts = $delivery->attempts + 1;
        $final = ! $result->succeeded() && $attempts >= self::MAX_ATTEMPTS;

        $delivery->forceFill([
            'attempts' => $attempts,
            'last_attempt_at' => now(),
            'last_status_code' => $result->statusCode,
            'last_error' => $result->error,
            'last_duration_ms' => $result->durationMs,
            'status' => match (true) {
                $result->succeeded() => ConnectWebhookDelivery::DELIVERED,
                $final => ConnectWebhookDelivery::FAILED,
                default => ConnectWebhookDelivery::PENDING,
            },
            'delivered_at' => $result->succeeded() ? now() : null,
            'next_attempt_at' => $result->succeeded() || $final ? null : now()->addSeconds(self::RETRY_DELAYS[$attempts - 1]),
        ])->save();

        $webhook->forceFill($result->succeeded() ? ['last_success_at' => now()] : ['last_failure_at' => now()])->save();

        if ($final) {
            $this->disableFailing($webhook, $delivery);
        }
    }

    /** Turns a webhook off for good after a delivery failed every attempt, and tells the owner. */
    private function disableFailing(ConnectWebhook $webhook, ConnectWebhookDelivery $delivery): void
    {
        DB::transaction(function () use ($webhook, $delivery): void {
            $webhook->forceFill(['disabled_at' => now(), 'disabled_reason' => ConnectWebhook::DISABLED_FAILING])->save();

            ConnectWebhookDelivery::query()->where('webhook_id', $webhook->id)->where('status', ConnectWebhookDelivery::PENDING)
                ->update(['status' => ConnectWebhookDelivery::CANCELLED, 'next_attempt_at' => null, 'updated_at' => now()]);

            Audit::record('connect.webhook.disabled', $webhook, [
                'url' => $webhook->url,
                'reason' => ConnectWebhook::DISABLED_FAILING,
                'event_id' => $delivery->event_id,
                'last_status_code' => $delivery->last_status_code,
                'last_error' => $delivery->last_error,
            ], $webhook->company_id);
        });

        $company = Company::query()->find($webhook->company_id);
        $owner = $company === null ? null : User::query()->find($company->user_id);
        $creator = User::query()->find($webhook->created_by);

        foreach (array_unique(array_filter([$owner?->email, $creator?->email])) as $email) {
            Mail::to($email)->send(new WebhookDisabledMail(
                (string) $company?->name,
                $webhook->url,
                $webhook->description,
                $delivery->event_type,
                $delivery->last_status_code,
                $delivery->last_error,
            ));
        }
    }
}
