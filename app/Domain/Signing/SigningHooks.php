<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Models\Customer;

/**
 * The module hooks of the signing follow-up (SigningHook), registered by
 * the modules' service providers. A singleton.
 */
final class SigningHooks
{
    /** @var list<SigningHook> */
    private array $hooks = [];

    public function add(SigningHook $hook): void
    {
        $this->hooks[] = $hook;
    }

    /** @return list<SigningHook> */
    public function all(): array
    {
        return $this->hooks;
    }

    /**
     * Who signs for the customer, from the first hook that knows
     * (ResolvesSigningParty), or null.
     *
     * @return array{name: string, legal_person: bool}|null
     */
    public function party(Customer $customer): ?array
    {
        foreach ($this->hooks as $hook) {
            $party = $hook instanceof ResolvesSigningParty ? $hook->signingParty($customer) : null;

            if ($party !== null) {
                return $party;
            }
        }

        return null;
    }
}
