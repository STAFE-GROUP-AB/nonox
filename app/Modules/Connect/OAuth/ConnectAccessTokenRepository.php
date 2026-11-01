<?php

declare(strict_types=1);

namespace App\Modules\Connect\OAuth;

use Illuminate\Support\Facades\DB;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Events\AccessTokenCreated;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use RuntimeException;

/**
 * Passport's access tokens, each tied to its grant (ADR-032). Only the
 * authorization code and refresh token grants reach this repository with a
 * grant; any other grant type is refused before Passport sees it.
 */
final class ConnectAccessTokenRepository extends AccessTokenRepository
{
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $grantId = app(GrantContext::class)->grantId ?? throw new RuntimeException('An access token needs a grant.');

        DB::table('oauth_access_tokens')->insert([
            'id' => $id = $accessTokenEntity->getIdentifier(),
            'user_id' => $userId = $accessTokenEntity->getUserIdentifier(),
            'client_id' => $clientId = $accessTokenEntity->getClient()->getIdentifier(),
            'scopes' => json_encode($accessTokenEntity->getScopes()),
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => $accessTokenEntity->getExpiryDateTime(),
            'connect_grant_id' => $grantId,
        ]);

        $this->events->dispatch(new AccessTokenCreated($id, $userId, $clientId));
    }
}
