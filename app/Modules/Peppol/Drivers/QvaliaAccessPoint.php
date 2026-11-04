<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Drivers;

use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use App\Modules\Peppol\Contracts\PeppolAccessPoint;
use App\Modules\Peppol\Domain\AccessPointFailed;
use App\Modules\Peppol\Domain\InboundDocument;
use App\Modules\Peppol\Domain\ParticipantLookup;
use App\Modules\Peppol\Domain\RegistrationStatus;
use App\Modules\Peppol\Domain\TransmissionStatus;
use App\Modules\Peppol\Domain\WebhookEvent;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Support\Peppol\PeppolIdentifier;
use App\Support\Peppol\UblInvoiceWriter;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Qvalia (Qvalia Group AB, Sweden), a Peppol service provider certified
 * under the Swedish Peppol Authority, through its Partner API: the operator
 * of the installation is the partner, each company a child account
 * (accountRegNo) under it (ADR-050).
 *
 * Built from Qvalia's public API documentation (https://api.qvalia.io, read
 * 2026-11-04): the Partner API, the Transaction API, Authentication and
 * Webhooks. Dormant until the operator has signed the partner agreement and
 * set the keys (services.peppol.qvalia); never called in tests (Http::fake).
 *
 * TODO(provider-verify) in Qvalia's sandbox (api-test.qvalia.com) before
 * going live: the participant id format in the lookup and registration
 * paths; the docTypes values for registration; the status path and field
 * under the partner prefix; that an incoming document fetched with
 * Accept: application/xml is the UBL exactly as received.
 */
final class QvaliaAccessPoint implements PeppolAccessPoint
{
    public const string KEY = 'qvalia';

    /** A signed webhook older than this is refused (Qvalia recommends five minutes). */
    private const int WEBHOOK_TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly string $url,
        private readonly string $partner,
        private readonly string $apiKey,
        private readonly string $webhookSecret,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            rtrim((string) (config('services.peppol.qvalia.url') ?: 'https://api-test.qvalia.com'), '/'),
            (string) config('services.peppol.qvalia.partner_reg_no'),
            (string) config('services.peppol.qvalia.api_key'),
            (string) config('services.peppol.qvalia.webhook_secret'),
        );
    }

    public static function isConfigured(): bool
    {
        return filled(config('services.peppol.qvalia.partner_reg_no')) && filled(config('services.peppol.qvalia.api_key'));
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function lookup(PeppolIdentifier $participant): ParticipantLookup
    {
        $data = (array) ($this->json($this->http()->get($this->partnerPath('peppol/lookup/'.rawurlencode($participant->toString()))), 'lookup')['data'] ?? []);
        $types = [];

        foreach ((array) ($data['matches'] ?? []) as $match) {
            foreach ((array) ($match['docTypes'] ?? []) as $type) {
                $types[] = (string) ($type['value'] ?? '');
            }
        }

        $has = fn (string $docType): bool => in_array($docType, $types, true) || in_array(preg_replace('/^busdox-docid-qns::/', '', $docType), $types, true);
        $exists = (bool) ($data['exists'] ?? false);

        return new ParticipantLookup($participant, $exists, $exists && $has(UblInvoiceWriter::DOCTYPE_INVOICE), $exists && $has(UblInvoiceWriter::DOCTYPE_CREDIT_NOTE));
    }

    public function send(PeppolParticipant $sender, string $xml, bool $creditNote, string $idempotencyKey): string
    {
        $response = $this->http()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey, 'Accept' => 'application/json'])
            ->withBody($xml, 'application/xml')
            ->post($this->transactionPath($sender, $creditNote ? 'creditnotes' : 'invoices', 'outgoing').'?delivery_method=peppol');

        $reference = (string) ($this->json($response, 'send')['data']['integrationId'] ?? '');

        if ($reference === '') {
            throw new AccessPointFailed('Qvalia accepted the document but gave no integrationId.', retryable: true);
        }

        return $reference;
    }

    public function deliveryStatus(PeppolParticipant $sender, string $reference, bool $creditNote): ?TransmissionStatus
    {
        $response = $this->http()->get($this->transactionPath($sender, $creditNote ? 'creditnotes' : 'invoices', 'outgoing').'/status', ['integrationId' => $reference]);
        $entry = (array) (((array) ($this->json($response, 'status')['data'] ?? []))[0] ?? []);

        return self::status((string) ($entry['metadata']['status'] ?? $entry['status'] ?? ''));
    }

    /**
     * Nothing is marked as read at Qvalia: a document is listed again until
     * the window has passed it, and NONOX takes the same bytes only once. So
     * a document fetched while the inbox could not take it (quota, a
     * failure) is fetched again on the next run instead of being lost.
     */
    public function incoming(PeppolParticipant $receiver): array
    {
        $documents = [];
        $since = ($receiver->last_polled_at ?? now()->subDays(7))->subHour()->toIso8601String();

        foreach (['invoices' => 'Invoice', 'creditnotes' => 'CreditNote'] as $path => $type) {
            $list = $this->json($this->http()->get($this->transactionPath($receiver, $path, 'incoming').'/status', ['updatedAtFrom' => $since, 'includeRead' => 'true', 'limit' => 100]), 'incoming');

            foreach ((array) ($list['data'] ?? []) as $entry) {
                $reference = (string) ($entry['integrationId'] ?? '');

                if ($reference !== '') {
                    $documents[] = $this->fetch($receiver, $reference, $type);
                }
            }
        }

        return $documents;
    }

    public function fetch(PeppolParticipant $receiver, string $reference, string $documentType): InboundDocument
    {
        $path = $documentType === 'CreditNote' ? 'creditnotes' : 'invoices';
        $response = $this->http()->accept('application/xml')
            ->get($this->transactionPath($receiver, $path, 'incoming'), ['integrationId' => $reference, 'includeRead' => 'true']);

        if (! $response->successful() || trim($response->body()) === '') {
            throw new AccessPointFailed("Qvalia fetch failed with HTTP {$response->status()}.", retryable: $response->serverError() || $response->status() === 429);
        }

        return new InboundDocument($reference, $response->body());
    }

    public function register(PeppolParticipant $participant, Company $company): RegistrationStatus
    {
        $org = OrganisationNumber::tryFrom((string) $company->org_number);

        $response = $this->http()->put($this->partnerPath('account/'.rawurlencode((string) $participant->provider_account).'/peppol/'.rawurlencode($participant->identifier)), [
            'businessCard' => [
                'companyName' => (string) ($company->legal_name ?: $company->name),
                'countryCode' => (string) ($company->country ?: 'SE'),
                'orgNr' => (string) $org?->digits,
                'VAT' => $company->vat_number,
            ],
            'docTypes' => [
                ['profile' => UblInvoiceWriter::PROFILE, 'document' => UblInvoiceWriter::DOCTYPE_INVOICE],
                ['profile' => UblInvoiceWriter::PROFILE, 'document' => UblInvoiceWriter::DOCTYPE_CREDIT_NOTE],
            ],
        ]);

        $status = (string) ($this->json($response, 'register')['status'] ?? '');

        return in_array($status, ['registered', 'updated'], true) ? RegistrationStatus::Registered : RegistrationStatus::Pending;
    }

    /**
     * A partner subscribes to each child account separately; Qvalia returns
     * the signing secret only in the response that creates the subscription
     * (Webhook API, read 2026-11-04).
     *
     * @return array{id: string, secret: ?string}
     */
    public function subscribeWebhook(PeppolParticipant $participant, string $url): array
    {
        if ($participant->provider_account === null) {
            throw new AccessPointFailed('The company has no account with the access point yet.');
        }

        $response = $this->http()->put($this->partnerPath('account/'.rawurlencode($participant->provider_account).'/webhook/configure'), [
            'url' => $url,
            'types' => ['new_document', 'document_delivery', 'document_error'],
        ]);
        $json = $this->json($response, 'webhook');
        $data = is_array($json['data'] ?? null) ? $json['data'] : $json;

        return ['id' => (string) ($data['id'] ?? ''), 'secret' => isset($data['signingSecret']) ? (string) $data['signingSecret'] : null];
    }

    /**
     * Qvalia signs a delivery with X-Qvalia-Signature "t=<unix>,v1=<hex>":
     * HMAC-SHA256 of "<t>.<raw body>" with the subscription's secret,
     * several v1 values while a secret is rotated. The secret belongs to the
     * account's subscription (stored on the participant) or, for an
     * installation with one subscription, services.peppol.qvalia.webhook_secret.
     * The time must be within five minutes and the event id
     * (X-Qvalia-Event-Id) not seen before.
     */
    public function webhookEvents(Request $request, Closure $secrets): array
    {
        $body = $request->getContent();
        [$timestamp, $signatures] = self::parseSignature((string) $request->header('X-Qvalia-Signature'));

        if ($timestamp === null || abs(time() - $timestamp) > self::WEBHOOK_TOLERANCE_SECONDS) {
            throw new AccessPointFailed('The webhook is too old or has no timestamp.');
        }

        // The account is read from the body only to choose the secret; nothing is trusted before the signature matches.
        $unverified = json_decode($body, true);
        $account = is_array($unverified) ? (string) ($unverified['accountRegNo'] ?? '') : '';
        $candidates = array_values(array_filter([...($account === '' ? [] : $secrets($account)), $this->webhookSecret], fn (string $secret): bool => $secret !== ''));

        if ($candidates === []) {
            throw new AccessPointFailed('No webhook secret for the account.');
        }

        $matches = false;

        foreach ($candidates as $secret) {
            $expected = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

            foreach ($signatures as $signature) {
                $matches = $matches || hash_equals($expected, $signature);
            }
        }

        if (! $matches) {
            throw new AccessPointFailed('The webhook signature does not match.');
        }

        $payload = $unverified;
        $eventId = (string) ($request->header('X-Qvalia-Event-Id') ?: (is_array($payload) ? ($payload['eventId'] ?? '') : ''));

        if (! is_array($payload) || $eventId === '') {
            throw new AccessPointFailed('The webhook body cannot be read.');
        }

        // Delivery is at least once: an event id already handled is acknowledged and ignored.
        if (! Cache::add("peppol:qvalia:event:{$eventId}", true, now()->addDays(31))) {
            return [];
        }

        $reference = (string) ($payload['integrationId'] ?? '');
        $documentType = (string) ($payload['documentType'] ?? 'Invoice');
        $direction = (string) ($payload['direction'] ?? '');

        if ($account === '' || $reference === '' || ! in_array($documentType, ['Invoice', 'CreditNote'], true)) {
            return [];
        }

        return match ((string) ($payload['eventType'] ?? '')) {
            'new_document' => $direction === 'incoming' ? [new WebhookEvent(WebhookEvent::INCOMING, $account, $reference, $documentType)] : [],
            'document_delivery' => $direction === 'outgoing' && ($status = self::status((string) ($payload['status']['status'] ?? ''))) !== null
                ? [new WebhookEvent(WebhookEvent::DELIVERY, $account, $reference, $documentType, $status)]
                : [],
            'document_error' => $direction === 'outgoing'
                ? [new WebhookEvent(WebhookEvent::DELIVERY, $account, $reference, $documentType, TransmissionStatus::Failed, mb_substr((string) ($payload['error'] ?? 'Delivery failed.'), 0, 500))]
                : [],
            default => [],
        };
    }

    /** @return array{0: ?int, 1: list<string>} */
    public static function parseSignature(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }

    /** Qvalia's delivery status is open-ended text; anything else means no change. */
    private static function status(string $status): ?TransmissionStatus
    {
        return match ($status) {
            'processed', 'processed_with_warning' => TransmissionStatus::Delivered,
            'error' => TransmissionStatus::Failed,
            default => null,
        };
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders(['Authorization' => $this->apiKey])->acceptJson()->timeout(30)->connectTimeout(10);
    }

    private function partnerPath(string $path): string
    {
        return "{$this->url}/partner/".rawurlencode($this->partner).'/'.$path;
    }

    private function transactionPath(PeppolParticipant $participant, string $type, string $direction): string
    {
        if ($participant->provider_account === null) {
            throw new AccessPointFailed('The company has no account with the access point yet.');
        }

        return $this->partnerPath('transaction/'.rawurlencode($participant->provider_account)."/{$type}/{$direction}");
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AccessPointFailed
     */
    private function json(Response $response, string $operation): array
    {
        if (! $response->successful()) {
            $type = (string) ($response->json('type') ?? '');

            throw new AccessPointFailed("Qvalia {$operation} failed with HTTP {$response->status()}".($type !== '' ? " ({$type})" : '').'.', retryable: $response->serverError() || $response->status() === 429);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
