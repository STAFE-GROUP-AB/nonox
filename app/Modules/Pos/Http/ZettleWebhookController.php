<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http;

use App\Modules\ModuleManager;
use App\Modules\Pos\Domain\ConnectionStatus;
use App\Modules\Pos\Domain\Provider;
use App\Modules\Pos\Jobs\ProcessPosWebhookJob;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosWebhookMessage;
use App\Modules\Pos\PosModule;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Zettle's Pusher webhook (ADR-042). The connection is found by the opaque
 * token in the address, never by the merchant id in the body. The envelope
 * is checked with the signing key Zettle gave when the subscription was
 * created: X-iZettle-Signature is hex(HMAC-SHA256(key, timestamp + "." +
 * payload)) over the envelope's own timestamp and payload strings. Zettle
 * re-delivers for days with the original timestamp, so seven days are
 * accepted; recording is idempotent. A verified message is stored and
 * queued, and the answer goes back at once (Zettle waits ten seconds).
 */
class ZettleWebhookController
{
    public const int MAX_AGE_DAYS = 7;

    public function __invoke(Request $request, string $token, ModuleManager $modules): Response
    {
        $connection = CompanyScope::bypass(fn (): ?PosConnection => PosConnection::query()
            ->where('webhook_token', $token)
            ->where('provider', Provider::Zettle->value)
            ->first());

        if (! $connection instanceof PosConnection || $connection->status === ConnectionStatus::Disconnected || ! $modules->isEnabled(PosModule::KEY, $connection->company_id)) {
            abort(404);
        }

        $body = $request->getContent();
        $envelope = json_decode($body, true);

        if (! is_array($envelope)) {
            return response('', 400);
        }

        // Sent while the subscription is created, before the key is known.
        if (($envelope['eventName'] ?? null) === 'TestMessage') {
            return response('', 200);
        }

        $key = $connection->credential('signing_key');
        $timestamp = $envelope['timestamp'] ?? null;
        $payload = $envelope['payload'] ?? null;
        $signature = (string) $request->header('X-iZettle-Signature', '');

        if ($key === null || ! is_string($timestamp) || ! is_string($payload) || $signature === '' || ! hash_equals(hash_hmac('sha256', $timestamp.'.'.$payload, $key), strtolower($signature))) {
            return response('', 401);
        }

        try {
            $sent = CarbonImmutable::parse($timestamp);
        } catch (Throwable) {
            return response('', 401);
        }

        if ($sent->lessThan(CarbonImmutable::now()->subDays(self::MAX_AGE_DAYS)) || $sent->greaterThan(CarbonImmutable::now()->addMinutes(10))) {
            return response('', 401);
        }

        $messageId = is_string($envelope['messageId'] ?? null) && $envelope['messageId'] !== '' ? mb_substr($envelope['messageId'], 0, 150) : hash('sha256', $body);

        CurrentCompany::run($connection->company_id, function () use ($connection, $messageId, $envelope, $body): void {
            $stored = DB::table('pos_webhook_messages')->insertOrIgnore([
                'company_id' => $connection->company_id,
                'pos_connection_id' => $connection->id,
                'message_id' => $messageId,
                'event_name' => mb_substr((string) ($envelope['eventName'] ?? ''), 0, 100),
                'body' => $body,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($stored === 0) {
                return;
            }

            $message = PosWebhookMessage::query()->where('pos_connection_id', $connection->id)->where('message_id', $messageId)->firstOrFail();

            if ($message->event_name === 'PurchaseCreated') {
                ProcessPosWebhookJob::dispatch($connection->company_id, $message->id);
            }
        });

        return response('', 200);
    }
}
