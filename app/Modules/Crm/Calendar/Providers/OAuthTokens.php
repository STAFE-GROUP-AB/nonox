<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar\Providers;

use Carbon\CarbonImmutable;

/** What a token endpoint answered. A refresh may leave out the refresh token: the old one is kept. */
final readonly class OAuthTokens
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public ?CarbonImmutable $expiresAt,
        public ?string $idToken = null,
    ) {}

    /**
     * The claims of the OpenID id token. Not verified: the token came
     * straight from the provider's token endpoint over TLS (OpenID Connect
     * Core 3.1.3.7), and it only says which account signed in.
     *
     * @return array<string, mixed>
     */
    public function claims(): array
    {
        $parts = explode('.', (string) $this->idToken);

        if (count($parts) !== 3) {
            return [];
        }

        $json = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

        return is_array($json) ? $json : [];
    }

    /** @param array<string, mixed> $json */
    public static function fromResponse(array $json): self
    {
        $access = $json['access_token'] ?? null;

        if (! is_string($access) || $access === '') {
            throw new CalendarProviderException('The token endpoint answered without an access token.', reconnect: true);
        }

        $refresh = $json['refresh_token'] ?? null;
        $expires = $json['expires_in'] ?? null;
        $id = $json['id_token'] ?? null;

        return new self(
            $access,
            is_string($refresh) && $refresh !== '' ? $refresh : null,
            is_numeric($expires) ? CarbonImmutable::now()->addSeconds((int) $expires) : null,
            is_string($id) && $id !== '' ? $id : null,
        );
    }
}
