<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Drivers;

use App\Models\Company;
use App\Modules\Peppol\Contracts\PeppolAccessPoint;
use App\Modules\Peppol\Domain\AccessPointFailed;
use App\Modules\Peppol\Domain\InboundDocument;
use App\Modules\Peppol\Domain\ParticipantLookup;
use App\Modules\Peppol\Domain\RegistrationStatus;
use App\Modules\Peppol\Domain\TransmissionStatus;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Support\Peppol\PeppolIdentifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The access point for development and tests: nothing leaves the
 * installation. Sending writes a line to the log (ids only) and keeps the
 * document in memory, every participant exists unless listed in $unknown,
 * and incoming documents are what a test put in $inbox. Refused in
 * production (AccessPoints).
 */
final class LogAccessPoint implements PeppolAccessPoint
{
    public const string KEY = 'log';

    /** @var list<array{account: string, xml: string, credit_note: bool, key: string, reference: string}> */
    public static array $sent = [];

    /** @var array<string, list<InboundDocument>> account => documents waiting */
    public static array $inbox = [];

    /** @var list<string> participants the lookup does not find */
    public static array $unknown = [];

    /** @var ?string an error the next send throws, for tests */
    public static ?string $failNext = null;

    public static bool $failRetryable = true;

    public static function reset(): void
    {
        self::$sent = [];
        self::$inbox = [];
        self::$unknown = [];
        self::$failNext = null;
        self::$failRetryable = true;
        self::$deregistered = [];
        self::$failDeregister = null;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function lookup(PeppolIdentifier $participant): ParticipantLookup
    {
        $known = ! in_array($participant->toString(), self::$unknown, true);

        return new ParticipantLookup($participant, $known, $known, $known);
    }

    public function send(PeppolParticipant $sender, string $xml, bool $creditNote, string $idempotencyKey): string
    {
        if (self::$failNext !== null) {
            $message = self::$failNext;
            self::$failNext = null;

            throw new AccessPointFailed($message, self::$failRetryable);
        }

        foreach (self::$sent as $earlier) {
            if ($earlier['key'] === $idempotencyKey) {
                return $earlier['reference'];
            }
        }

        $reference = 'log-'.Str::uuid()->toString();
        self::$sent[] = ['account' => (string) $sender->provider_account, 'xml' => $xml, 'credit_note' => $creditNote, 'key' => $idempotencyKey, 'reference' => $reference];
        Log::info('Peppol (log driver): document handed over.', ['company_id' => $sender->company_id, 'reference' => $reference, 'sha256' => hash('sha256', $xml)]);

        return $reference;
    }

    public function deliveryStatus(PeppolParticipant $sender, string $reference, bool $creditNote): TransmissionStatus
    {
        return TransmissionStatus::Delivered;
    }

    public function incoming(PeppolParticipant $receiver): array
    {
        $documents = self::$inbox[(string) $receiver->provider_account] ?? [];
        self::$inbox[(string) $receiver->provider_account] = [];

        return $documents;
    }

    public function fetch(PeppolParticipant $receiver, string $reference, string $documentType): InboundDocument
    {
        foreach (self::$inbox[(string) $receiver->provider_account] ?? [] as $document) {
            if ($document->reference === $reference) {
                return $document;
            }
        }

        throw new AccessPointFailed("Document {$reference} not found.");
    }

    public function register(PeppolParticipant $participant, Company $company): RegistrationStatus
    {
        return RegistrationStatus::Registered;
    }

    /** @var list<string> participants deregistered, for tests */
    public static array $deregistered = [];

    public static ?string $failDeregister = null;

    public function deregister(PeppolParticipant $participant): void
    {
        if (self::$failDeregister !== null) {
            throw new AccessPointFailed(self::$failDeregister, retryable: true);
        }

        self::$deregistered[] = $participant->identifier;
    }

    public function subscribeWebhook(PeppolParticipant $participant, string $url): ?array
    {
        return null;
    }

    public function webhookEvents(Request $request, Closure $secrets): array
    {
        throw new AccessPointFailed('The log driver has no webhook.');
    }
}
