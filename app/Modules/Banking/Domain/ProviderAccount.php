<?php

declare(strict_types=1);

namespace App\Modules\Banking\Domain;

/**
 * An account in a consent. $id is valid only within the consent;
 * $identificationHash stays the same when the company connects again.
 */
final readonly class ProviderAccount
{
    public function __construct(
        public string $id,
        public string $identificationHash,
        public ?string $iban,
        public ?string $name,
        public string $currency,
    ) {}
}
