<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\User;

/**
 * An envelope-aware module hook (ADR-046 amendment, gap 1). A SigningHook
 * that also implements this interface is told once when every signer of an
 * envelope has signed, with the envelope and the signer who completed it.
 * A hook without it keeps getting SigningHook::signed() with that signer's
 * request (the one-signer adapter in SigningFollowUp), so CRM and WorkOrders
 * need no change: every signer row of an envelope carries the same document,
 * deal and invoice choice.
 */
interface SigningEnvelopeHook
{
    /**
     * After the core's own steps, once per completed envelope.
     *
     * @return list<string> steps that could not be taken, for the audit log and the notice
     */
    public function completed(Company $company, SigningEnvelope $envelope, SigningRequest $last, ?User $actor, ?string $orderId, ?string $invoiceId): array;
}
