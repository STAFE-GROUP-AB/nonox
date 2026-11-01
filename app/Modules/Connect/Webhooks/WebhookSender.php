<?php

declare(strict_types=1);

namespace App\Modules\Connect\Webhooks;

use App\Modules\Connect\Models\ConnectWebhook;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Makes one delivery attempt (ADR-032): checks the address again, signs the
 * body and POSTs it to the address checked, with short timeouts, no
 * redirects, TLS verified against the host name and a cap on how much of
 * the answer is read. Only the status code is kept; the answer itself is
 * thrown away.
 */
class WebhookSender
{
    public const int CONNECT_TIMEOUT_SECONDS = 5;

    public const int TIMEOUT_SECONDS = 10;

    /** Bytes of the answer we accept before giving up on it. */
    public const int MAX_RESPONSE_BYTES = 65536;

    public const string USER_AGENT = 'NONOX-Webhooks/1.0 (+https://github.com/STAFE-GROUP-AB/nonox)';

    public function __construct(private readonly WebhookAddress $address) {}

    public function send(ConnectWebhook $webhook, string $eventId, string $eventType, string $body): WebhookAttempt
    {
        $started = hrtime(true);

        try {
            $target = $this->address->target($webhook->url);
        } catch (UnsafeWebhookAddress $exception) {
            return new WebhookAttempt(null, 'address:'.$exception->reason, self::since($started));
        }

        $timestamp = time();

        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Nonox-Event-Id' => $eventId,
                'Nonox-Event-Type' => $eventType,
                'Nonox-Signature' => WebhookSignature::header($webhook->secret, $timestamp, $body),
            ])
                ->withBody($body, 'application/json')
                ->withOptions([
                    'allow_redirects' => false,
                    'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                    'timeout' => self::TIMEOUT_SECONDS,
                    'verify' => true,
                    'proxy' => '',
                    'curl' => [
                        // Connect to the address that was checked, whatever DNS answers now.
                        CURLOPT_RESOLVE => [$target->curlResolve()],
                        CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES,
                        CURLOPT_NOPROGRESS => false,
                        CURLOPT_PROGRESSFUNCTION => static fn (mixed $handle, int $downloadTotal, int $downloaded): int => $downloaded > self::MAX_RESPONSE_BYTES ? 1 : 0,
                    ],
                ])
                ->post($webhook->url);
        } catch (ConnectionException) {
            return new WebhookAttempt(null, 'connection_failed', self::since($started));
        } catch (Throwable) {
            return new WebhookAttempt(null, 'request_failed', self::since($started));
        }

        $status = $response->status();

        return new WebhookAttempt($status, $status >= 200 && $status < 300 ? null : "http_{$status}", self::since($started));
    }

    private static function since(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
