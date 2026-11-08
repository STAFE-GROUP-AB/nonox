<?php

declare(strict_types=1);

namespace App\Domain\Signing;

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
    public static function isQuoteSigned(string $quoteId): bool
    {
        return SigningRequest::query()
            ->where('sales_document_id', $quoteId)
            ->where('status', SigningStatus::Signed->value)
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
