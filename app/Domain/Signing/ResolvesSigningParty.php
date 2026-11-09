<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Models\Customer;

/**
 * A SigningHook that knows more about who signs for a customer than the
 * customer register does (CRM: the account's kind and name). The quote's
 * acceptance text asks the hooks first and falls back to the register.
 */
interface ResolvesSigningParty
{
    /** @return array{name: string, legal_person: bool}|null null when the hook knows nothing about the customer */
    public function signingParty(Customer $customer): ?array;
}
