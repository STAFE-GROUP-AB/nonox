<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Http;

use App\Modules\ModuleManager;
use App\Modules\Saas\SaasModule;
use App\Modules\Saas\Stripe\Actions\StripePermissions;
use App\Modules\Saas\Stripe\Domain\WebhookSignature;
use App\Modules\Saas\Stripe\Jobs\SyncStripe;
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Modules\Saas\Stripe\Models\StripeEvent;
use App\Modules\Saas\Stripe\Support\StripeSync;
use App\Support\Connect\ConnectAddons;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /webhooks/stripe/{token} (ADR-080 §7.4): events from the company's
 * own Stripe account, optional, for speed. No session, no CSRF, throttled
 * per token.
 *
 * 1. The connection is found by its opaque token before anything else; an
 *    unknown token, an ended connection, one without a signing secret, or a
 *    company that has NONOX SaaS or Stripe in NONOX Connect switched off all
 *    answer 404, so the endpoint reveals no company.
 * 2. The Stripe-Signature is checked against the raw body with the
 *    connection's secret (WebhookSignature); a bad one answers 400 and
 *    nothing is stored.
 * 3. The event id is stored once per connection (a repeated delivery is
 *    answered 200 and does nothing), and a sync is queued. The payload is a
 *    hint only: the sync fetches the object again with the company's key, so
 *    a forged or replayed event can at most cause a harmless re-fetch.
 */
class StripeWebhookController
{
    public function __invoke(Request $request, string $token, ModuleManager $modules): Response
    {
        $connection = CompanyScope::bypass(fn (): ?StripeConnection => StripeConnection::query()->where('webhook_token', $token)->first());

        if (! StripePermissions::available()
            || ! $connection instanceof StripeConnection
            || ! $connection->isActive()
            || ! $connection->hasWebhook()
            || ! $modules->isEnabled(SaasModule::KEY, $connection->company_id)
            || ! ConnectAddons::isOn($connection->company_id, ConnectAddons::STRIPE)) {
            return response('', 404);
        }

        $body = $request->getContent();

        if (! WebhookSignature::verify((string) $request->header('Stripe-Signature', ''), $body, [(string) $connection->webhook_secret], now()->getTimestamp())) {
            return response('', 400);
        }

        $event = json_decode($body, true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || preg_match('/^evt_[A-Za-z0-9]{1,250}$/', $event['id']) !== 1) {
            return response('', 400);
        }

        // An event from the other mode (test events to a live connection) is acknowledged and ignored.
        if (($event['livemode'] ?? $connection->livemode) !== $connection->livemode || ! in_array($event['type'] ?? '', StripeSync::EVENT_TYPES, true)) {
            return response('', 200);
        }

        $inserted = CurrentCompany::run($connection->company_id, fn (): int => StripeEvent::query()->insertOrIgnore([
            'company_id' => $connection->company_id,
            'connection_id' => $connection->id,
            'event_id' => $event['id'],
            'type' => (string) $event['type'],
            'object_id' => StripeSync::eventObject($event),
            'event_created' => (int) ($event['created'] ?? 0),
            'received_from' => StripeEvent::FROM_WEBHOOK,
            'received_at' => now(),
        ]));

        if ($inserted > 0) {
            SyncStripe::dispatch($connection->company_id, $connection->id);
        }

        return response('', 200);
    }
}
