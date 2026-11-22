<?php

declare(strict_types=1);

namespace App\Domain\Signing\Providers;

use App\Models\SigningProvider;
use SensitiveParameter;

/**
 * What a driver sees of one signer's run (ADR-046 S17): the company's
 * configuration, where the browser comes back to, the OAuth state of the
 * next redirect (fresh for each step; NONOX stores only its hash), the
 * signer's language, and the driver's own working data, which NONOX keeps
 * encrypted for the run and empties when it ends. Never dumped.
 */
final class ProviderRun
{
    /**
     * @param  array<string, mixed>  $secrets  the driver's working data between steps
     */
    public function __construct(
        public readonly SigningProvider $config,
        public readonly string $redirectUri,
        public string $state,
        public readonly string $language,
        #[SensitiveParameter] public array $secrets = [],
        public readonly string $documentCode = '',
    ) {}

    /**
     * A short code for the document the signer was shown (ADR-046 S17, legal review F7): the first eight hex digits
     * of its SHA-256, upper case, in two groups ("1A2B 3C4D"). Shown on the signing page and sent to the provider,
     * so the signer can see that the service signs the document they read. No personal data.
     */
    public static function documentCode(string $sha256): string
    {
        $hex = strtoupper(substr($sha256, 0, 8));

        return substr($hex, 0, 4).' '.substr($hex, 4, 4);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['provider_id' => $this->config->id, 'driver' => $this->config->driver, 'secrets' => '[hidden]', 'state' => '[hidden]'];
    }
}
