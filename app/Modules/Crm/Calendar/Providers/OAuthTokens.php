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
    ) {}

    /** @param array<string, mixed> $json */
    public static function fromResponse(array $json): self
    {
        $access = $json['access_token'] ?? null;

        if (! is_string($access) || $access === '') {
            throw new CalendarProviderException('The token endpoint answered without an access token.', reconnect: true);
        }

        $refresh = $json['refresh_token'] ?? null;
        $expires = $json['expires_in'] ?? null;

        return new self(
            $access,
            is_string($refresh) && $refresh !== '' ? $refresh : null,
            is_numeric($expires) ? CarbonImmutable::now()->addSeconds((int) $expires) : null,
        );
    }
}
