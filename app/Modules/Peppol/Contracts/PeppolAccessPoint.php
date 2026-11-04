<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Contracts;

use App\Models\Company;
use App\Modules\Peppol\Domain\AccessPointFailed;
use App\Modules\Peppol\Domain\InboundDocument;
use App\Modules\Peppol\Domain\ParticipantLookup;
use App\Modules\Peppol\Domain\RegistrationStatus;
use App\Modules\Peppol\Domain\TransmissionStatus;
use App\Modules\Peppol\Domain\WebhookEvent;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Support\Peppol\PeppolIdentifier;
use Closure;
use Illuminate\Http\Request;

/**
 * A certified Peppol access point that NONOX talks to on behalf of its
 * companies (AGENTS.md §7 Phase 2: "a pluggable access-point driver
 * interface (no hard dependency on one provider)"). NONOX is not an access
 * point itself (ADR-050). One driver per installation: the agreement with
 * the provider is the operator's; each company has its own account there.
 *
 * Every method that talks to the provider throws AccessPointFailed.
 */
interface PeppolAccessPoint
{
    public function key(): string;

    /** Whether the participant is on the network and takes BIS Billing 3.0 invoices and credit notes (an SMP lookup). */
    public function lookup(PeppolIdentifier $participant): ParticipantLookup;

    /**
     * Hands the UBL document to the access point for the sender's account.
     * $idempotencyKey is the same for every attempt of one transmission.
     *
     * @return string the provider's reference for the document
     *
     * @throws AccessPointFailed
     */
    public function send(PeppolParticipant $sender, string $xml, bool $creditNote, string $idempotencyKey): string;

    /** Where a sent document is, or null when the provider does not know more yet. */
    public function deliveryStatus(PeppolParticipant $sender, string $reference, bool $creditNote): ?TransmissionStatus;

    /**
     * Documents received for the account since the last call (polling; the
     * webhook is the faster way where the provider has one).
     *
     * @return list<InboundDocument>
     */
    public function incoming(PeppolParticipant $receiver): array;

    /** One received document by the provider's reference (after a webhook said it arrived). */
    public function fetch(PeppolParticipant $receiver, string $reference, string $documentType): InboundDocument;

    /** Publishes the company as a receiver of BIS Billing 3.0 in the provider's SMP. */
    public function register(PeppolParticipant $participant, Company $company): RegistrationStatus;

    /**
     * Removes the company as a receiver from the provider's SMP (and so the
     * Peppol Directory), so suppliers stop sending to it.
     *
     * @throws AccessPointFailed
     */
    public function deregister(PeppolParticipant $participant): void;

    /**
     * Subscribes the installation's webhook URL to the account's events.
     *
     * @return ?array{id: string, secret: ?string} null when the provider has no webhooks
     */
    public function subscribeWebhook(PeppolParticipant $participant, string $url): ?array;

    /**
     * Verifies a webhook request (signature, age, replay) and reads it.
     * $secrets gives the signing secrets for a provider account; the body is
     * trusted only after the signature matched one of them.
     *
     * @param  Closure(string): list<string>  $secrets
     * @return list<WebhookEvent>
     *
     * @throws AccessPointFailed when it cannot be verified
     */
    public function webhookEvents(Request $request, Closure $secrets): array;
}
