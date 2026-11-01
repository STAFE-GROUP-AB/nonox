<?php

declare(strict_types=1);

namespace App\Modules\Connect\OAuth;

use Illuminate\Support\Facades\DB;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Events\RefreshTokenCreated;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use RuntimeException;

/**
 * Refresh tokens with rotation and reuse detection (ADR-032, RFC 9700
 * §4.14.2). Each use revokes the old token and issues a new one. A refresh
 * token that comes back after it was used means two parties hold it, so
 * the whole grant is revoked: the app and the attacker both have to start
 * over, and the company's owner sees it in the audit log.
 */
final class ConnectRefreshTokenRepository extends RefreshTokenRepository
{
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $grantId = app(GrantContext::class)->grantId ?? throw new RuntimeException('A refresh token needs a grant.');

        DB::table('oauth_refresh_tokens')->insert([
            'id' => $id = $refreshTokenEntity->getIdentifier(),
            'access_token_id' => $accessTokenId = $refreshTokenEntity->getAccessToken()->getIdentifier(),
            'revoked' => false,
            'expires_at' => $refreshTokenEntity->getExpiryDateTime(),
            'connect_grant_id' => $grantId,
        ]);

        $this->events->dispatch(new RefreshTokenCreated($id, $accessTokenId));
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        // Locked until the token request commits: two parallel refreshes with the same token are a reuse.
        $token = DB::table('oauth_refresh_tokens')->where('id', $tokenId)->lockForUpdate()->first(['revoked', 'connect_grant_id']);

        if ($token === null || ! is_string($token->connect_grant_id)) {
            return true;
        }

        if ((bool) $token->revoked) {
            GrantTokens::revoke($token->connect_grant_id, GrantTokens::REFRESH_TOKEN_REUSED);

            return true;
        }

        if (! Grants::isActive($token->connect_grant_id)) {
            return true;
        }

        app(GrantContext::class)->grantId = $token->connect_grant_id;

        return false;
    }
}
