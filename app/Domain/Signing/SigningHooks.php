<?php

declare(strict_types=1);

namespace App\Domain\Signing;

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
}
