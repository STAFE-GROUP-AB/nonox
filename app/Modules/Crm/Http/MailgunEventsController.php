<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Actions\MailingResponses;
use App\Modules\Crm\Models\MailingRecipient;
use App\Modules\Inbox\Http\MailgunWebhookController;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Mailgun's event webhook for mailings (ADR-048): delivered, failed and
 * complained, as JSON with the signature inside. Trusted only with
 * Mailgun's signature (the same check and replay guard as the inbox,
 * ADR-023). The recipient is found by the id NONOX put in the message's
 * X-Mailgun-Variables, and the event must be for that recipient's address.
 * Every signed request answers 200, so nothing is learned from it.
 */
class MailgunEventsController
{
    public function __invoke(Request $request, MailingResponses $responses): Response
    {
        $signature = (array) $request->input('signature', []);
        $timestamp = (string) ($signature['timestamp'] ?? '');
        $token = (string) ($signature['token'] ?? '');

        if (! MailgunWebhookController::validSignature($timestamp, $token, (string) ($signature['signature'] ?? ''))) {
            Log::warning('CRM mailing: refused a Mailgun event with a bad or old signature.');

            return response('', 406);
        }

        if (! Cache::add('crm:mailgun-event:'.hash('sha256', $token), true, MailgunWebhookController::MAX_AGE_SECONDS * 2)) {
            return response('', 406);
        }

        $event = (array) $request->input('event-data', []);
        $variables = (array) ($event['user-variables'] ?? []);
        $id = $variables['nonox_recipient'] ?? null;

        if (! is_numeric($id)) {
            return response('', 200);
        }

        // The event names no company: the recipient id decides it.
        $recipient = CompanyScope::bypass(fn (): ?MailingRecipient => MailingRecipient::query()->find((int) $id));

        if ($recipient instanceof MailingRecipient) {
            $responses->event($recipient, (string) ($event['event'] ?? ''), (string) ($event['severity'] ?? ''), (string) ($event['recipient'] ?? ''));
        }

        return response('', 200);
    }
}
