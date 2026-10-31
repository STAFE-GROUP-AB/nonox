<?php

declare(strict_types=1);

namespace App\Migration\Fortnox;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fortnox's OAuth2 authorization code flow with PKCE, as documented at
 * fortnox.se/developer/authorization: the user authorises on
 * apps.fortnox.se, and the code is exchanged for an access token (one hour)
 * and a refresh token (45 days, replaced on every refresh). The client id
 * and secret are sent as HTTP Basic credentials.
 *
 * TODO(legal-verify): Fortnox's developer and integration terms for
 * reading another company's data on its behalf (AGENTS.md §14.1).
 */
final class FortnoxOAuth
{
    public const string AUTHORIZE_URL = 'https://apps.fortnox.se/oauth-v1/auth';

    public const string TOKEN_URL = 'https://apps.fortnox.se/oauth-v1/token';

    /** Read access to what a move needs; every Fortnox scope also allows writing, which NONOX never does. */
    public const array SCOPES = ['companyinformation', 'bookkeeping', 'customer', 'supplier', 'article', 'invoice', 'supplierinvoice', 'archive', 'connectfile'];

    public static function isConfigured(): bool
    {
        return filled(config('services.fortnox.client_id')) && filled(config('services.fortnox.client_secret'));
    }

    public static function authorizeUrl(string $state, string $codeVerifier, string $redirectUri): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => (string) config('services.fortnox.client_id'),
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'access_type' => 'offline',
            'response_type' => 'code',
            'code_challenge' => self::challenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function challenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}
     */
    public static function exchange(string $code, string $codeVerifier, string $redirectUri): array
    {
        return self::token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}
     */
    public static function refresh(string $refreshToken): array
    {
        return self::token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /**
     * @param  array<string, string>  $body
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}
     */
    private static function token(array $body): array
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth((string) config('services.fortnox.client_id'), (string) config('services.fortnox.client_secret'))
                ->timeout(20)
                ->post(self::TOKEN_URL, $body)
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException('Fortnox did not issue a token.', 0, $exception);
        }

        $token = $response->json();

        if (! is_array($token) || ! is_string($token['access_token'] ?? null) || ! is_string($token['refresh_token'] ?? null)) {
            throw new RuntimeException('Fortnox answered without a token.');
        }

        return [
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'expires_in' => (int) ($token['expires_in'] ?? 3600),
            'scope' => (string) ($token['scope'] ?? ''),
        ];
    }
}
