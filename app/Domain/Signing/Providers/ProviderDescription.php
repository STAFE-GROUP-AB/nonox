<?php

declare(strict_types=1);

namespace App\Domain\Signing\Providers;

/**
 * What a service says about itself when a configuration is checked (ADR-046
 * S17; for CSC the info method): its commercial name, its country, and the
 * OAuth address it uses. Stored on the configuration for display only.
 */
final readonly class ProviderDescription
{
    public function __construct(
        public string $name,
        public ?string $region,
        public ?string $oauthUrl,
    ) {}
}
