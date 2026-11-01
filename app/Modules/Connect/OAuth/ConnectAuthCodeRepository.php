<?php

declare(strict_types=1);

namespace App\Modules\Connect\OAuth;

use Illuminate\Support\Facades\DB;
use Laravel\Passport\Bridge\AuthCodeRepository;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use RuntimeException;

/**
 * Passport's authorization codes, each tied to the grant the consent created
 * (ADR-032). A code that is redeemed twice revokes the whole grant
 * (RFC 6749 §4.1.2): the second attempt is an attacker or a replay.
 */
final class ConnectAuthCodeRepository extends AuthCodeRepository
{
    public function __construct(private readonly GrantContext $context) {}

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $grantId = $this->context->grantId ?? throw new RuntimeException('An authorization code needs a grant.');

        DB::table('oauth_auth_codes')->insert([
            'id' => $authCodeEntity->getIdentifier(),
            'user_id' => $authCodeEntity->getUserIdentifier(),
            'client_id' => $authCodeEntity->getClient()->getIdentifier(),
            'scopes' => json_encode($authCodeEntity->getScopes()),
            'revoked' => false,
            'expires_at' => $authCodeEntity->getExpiryDateTime(),
            'connect_grant_id' => $grantId,
        ]);
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        // Locked until the token request commits, so a code cannot be redeemed twice in parallel.
        $code = DB::table('oauth_auth_codes')->where('id', $codeId)->lockForUpdate()->first(['revoked', 'connect_grant_id']);

        if ($code === null || ! is_string($code->connect_grant_id)) {
            return true;
        }

        if ((bool) $code->revoked) {
            GrantTokens::revoke($code->connect_grant_id, GrantTokens::CODE_REUSED);

            return true;
        }

        if (! Grants::isActive($code->connect_grant_id)) {
            return true;
        }

        $this->context->grantId = $code->connect_grant_id;

        return false;
    }
}
