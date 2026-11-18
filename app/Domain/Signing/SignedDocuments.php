<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Agreement;

/**
 * What has been signed at a CRM signing link (ADR-046), for the invoicing
 * core and the GDPR tools. A signed quote says what was signed: it cannot be
 * changed or sent for signing again. A customer with a signed agreement is
 * anonymised, never deleted, so the agreement and its evidence stay. Read
 * inside the company; the tables exist whether or not CRM is on.
 */
final class SignedDocuments
{
    /**
     * A quote is signed (fixed) once anyone has signed it, unless the
     * envelope of that signature was later withdrawn or declined: an
     * envelope with several signers that is still out keeps the quote fixed
     * so the next signer signs what the first signed (ADR-046 amendment,
     * gap 1); a withdrawn or declined one lets the seller change it and
     * send again.
     */
    public static function isQuoteSigned(string $quoteId): bool
    {
        return SigningRequest::query()
            ->where('sales_document_id', $quoteId)
            ->where('status', SigningStatus::Signed->value)
            ->where(fn ($query) => $query
                ->whereNull('envelope_id')
                ->orWhereNotIn('envelope_id', SigningEnvelope::query()->select('id')->where(fn ($released) => $released
                    ->whereIn('status', [EnvelopeStatus::Voided->value, EnvelopeStatus::Declined->value])
                    // An envelope still out past its deadline can no longer be completed: the quote is free again.
                    ->orWhere(fn ($expired) => $expired->where('status', EnvelopeStatus::Out->value)->where('deadline_at', '<=', now())))))
            ->exists();
    }

    /** Whether the document is out in an envelope with several signers that can still be completed (out, deadline ahead). */
    public static function awaitsSeveralSigners(Signable $document, SignableTypes $types): bool
    {
        return SigningEnvelope::query()
            ->where('document_type', $document->signingType())
            ->where($types->column($document->signingType()), $document->signingKey())
            ->where('status', EnvelopeStatus::Out->value)
            ->where(fn ($query) => $query->whereNull('deadline_at')->orWhere('deadline_at', '>', now()))
            ->has('signers', '>', 1)
            ->exists();
    }

    public static function customerHasSignedAgreement(int $customerId): bool
    {
        return Agreement::query()
            ->whereNotNull('signed_at')
            ->whereIn('account_id', Account::query()->where('customer_id', $customerId)->select('id'))
            ->exists();
    }
}
