<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Http;

use App\Modules\Inbox\Actions\RouteInboundMail;
use App\Modules\Inbox\Support\InboxQuotaExceeded;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Receives mail from a Mailgun inbound route that forwards the raw MIME
 * (a route action ending in /mime, so the request carries "body-mime").
 *
 * The request is trusted only when its signature is Mailgun's: HMAC-SHA256
 * of timestamp and token with the webhook signing key, a timestamp at most
 * five minutes old, and a token not seen before. Every accepted request
 * answers 200, whether or not a company took the message, so the endpoint
 * says nothing about which addresses exist. A company over its hourly
 * quota answers 429 so Mailgun delivers again later.
 */
class MailgunWebhookController
{
    public const int MAX_AGE_SECONDS = 300;

    public function __invoke(Request $request, RouteInboundMail $route): Response
    {
        $timestamp = (string) $request->input('timestamp', '');
        $token = (string) $request->input('token', '');
        $signature = (string) $request->input('signature', '');

        if (! self::validSignature($timestamp, $token, $signature)) {
            Log::warning('Inbox: refused a Mailgun request with a bad or old signature.');

            return response('', 406);
        }

        // Taken before anything is done, so the same signed request sent twice at once runs once.
        if (! Cache::add(self::tokenKey($token), true, self::MAX_AGE_SECONDS * 2)) {
            return response('', 406);
        }

        $raw = (string) $request->input('body-mime', '');

        if ($raw === '') {
            return response('', 200);
        }

        $recipients = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('recipient', '')))));
        $transport = array_filter([
            'X-Mailgun-Spf' => (string) $request->input('X-Mailgun-Spf', ''),
            'X-Mailgun-Dkim-Check-Result' => (string) $request->input('X-Mailgun-Dkim-Check-Result', ''),
            'X-Mailgun-Sflag' => (string) $request->input('X-Mailgun-Sflag', ''),
            'sender' => (string) $request->input('sender', ''),
        ]);

        try {
            $route($raw, $recipients, $transport);
        } catch (InboxQuotaExceeded) {
            // Mailgun retries later with a new token; this one is spent either way.
            return response('', 429);
        }

        return response('', 200);
    }

    public static function validSignature(string $timestamp, string $token, string $signature): bool
    {
        $key = (string) config('services.mailgun.webhook_signing_key');

        if ($key === '' || $token === '' || $signature === '' || preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > self::MAX_AGE_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.$token, $key), $signature);
    }

    private static function tokenKey(string $token): string
    {
        return 'inbox:mailgun-token:'.hash('sha256', $token);
    }
}
