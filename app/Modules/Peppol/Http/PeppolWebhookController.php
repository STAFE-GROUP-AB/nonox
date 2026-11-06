<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Http;

use App\Modules\Peppol\Domain\AccessPointFailed;
use App\Modules\Peppol\Jobs\HandlePeppolEvent;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\Support\AccessPoints;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/peppol/{driver}: events from the installation's access
 * point. No session, no CSRF: the request is trusted only when the driver
 * verifies it (for Qvalia an HMAC-SHA256 signature, at most five minutes
 * old, and an event id not seen before). Each event is handled in a queued
 * job for the company that owns the provider account; an account no
 * company has is answered 200 and ignored, so the endpoint reveals nothing.
 */
class PeppolWebhookController
{
    public function __invoke(Request $request, string $driver): Response
    {
        if ($driver !== AccessPoints::driver() || ! AccessPoints::isConfigured()) {
            return response('', 404);
        }

        try {
            // The signing secret of the account's subscription, looked up across companies.
            $secrets = function (string $account) use ($driver): array {
                $secret = CompanyScope::bypass(fn (): ?string => PeppolParticipant::query()
                    ->where('provider', $driver)
                    ->where('provider_account', $account)
                    ->first()?->webhook_secret);

                return $secret === null || $secret === '' ? [] : [$secret];
            };
            // Verified first, also while paused, so an unsigned request learns nothing about the pause.
            $paused = AccessPoints::isPaused($driver);
            $events = AccessPoints::make($driver, evenWhenPaused: true)->webhookEvents($request, $secrets, markSeen: ! $paused);
        } catch (AccessPointFailed $exception) {
            Log::warning('Peppol webhook refused.', ['driver' => $driver, 'reason' => $exception->getMessage()]);

            return response('', 401);
        }

        // Paused by the operator: 503 to a verified request, so the provider delivers the event again later (and
        // peppol:sync catches up). Its event id was not recorded, so the redelivery is handled.
        if ($paused) {
            return response('', 503, ['Retry-After' => '900']);
        }

        foreach ($events as $event) {
            // The provider account says which company; looked up across companies, then handled inside that one.
            $companyId = CompanyScope::bypass(fn (): ?int => PeppolParticipant::query()
                ->where('provider', $driver)
                ->where('provider_account', $event->account)
                ->value('company_id'));

            if ($companyId !== null) {
                dispatch(HandlePeppolEvent::for((int) $companyId, $event));
            }
        }

        return response('', 200);
    }
}
