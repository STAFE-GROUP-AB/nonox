<?php

declare(strict_types=1);

namespace App\Support\Skatteverket;

use App\Models\SkatteverketToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Signing in with Skatteverket (ADR-054): the authorization code flow where
 * the person proves who they are with e-legitimation (ACG "per").
 *
 * Skatteverket's pages "Authorization Code Grant" and "Så fungerar access
 * tokens": response_type=code only, the code lives five minutes, the token
 * endpoint takes a form-encoded body with the OAuth2 client id and secret,
 * an access token lives 3600 seconds and the per flow also gives a refresh
 * token that lives 65 minutes. A token is reused for its whole life:
 * Skatteverket caps how many valid tokens one identity may hold.
 */
final class SkatteverketAuth
{
    /** Margin so a token is not used in its last minute. */
    private const int MARGIN_SECONDS = 60;

    /** Skatteverket: a refresh token lives 65 minutes. */
    private const int REFRESH_MINUTES = 65;

    public static function authorizeUrl(string $state, string $redirectUri): string
    {
        return Skatteverket::personAuthorizationServer().'/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => Skatteverket::oauthClientId(),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            // TODO(provider-verify): Skatteverket allows several scopes in one token but does not state the separator; OAuth 2.0 (RFC 6749 3.3) uses a space. Confirm in the test service.
            'scope' => implode(' ', Skatteverket::SCOPES),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Exchanges the code from the callback and stores the person's tokens. */
    public static function exchange(User $user, string $code, string $redirectUri): SkatteverketToken
    {
        return self::store($user, self::token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]));
    }

    /**
     * A valid access token for the person, refreshed when it has run out and
     * the refresh token still lives; null means: sign in with Skatteverket.
     */
    public static function accessToken(User $user): ?string
    {
        $token = SkatteverketToken::query()->where('user_id', $user->id)->first();

        if ($token === null) {
            return null;
        }

        $now = CarbonImmutable::now();

        if ($token->expires_at->subSeconds(self::MARGIN_SECONDS)->greaterThan($now)) {
            return $token->access_token;
        }

        if ($token->refresh_token === null || $token->refresh_expires_at === null || $token->refresh_expires_at->subSeconds(self::MARGIN_SECONDS)->lessThanOrEqualTo($now)) {
            $token->delete();

            return null;
        }

        try {
            // TODO(provider-verify): the refresh request is not described by Skatteverket; this is the OAuth 2.0 form (RFC 6749 6). Confirm in the test service.
            return self::store($user, self::token(['grant_type' => 'refresh_token', 'refresh_token' => $token->refresh_token]))->access_token;
        } catch (SkatteverketFailed) {
            $token->delete();

            return null;
        }
    }

    public static function isSignedIn(User $user): bool
    {
        return SkatteverketToken::query()->where('user_id', $user->id)
            ->where(fn ($query) => $query->where('expires_at', '>', now()->addSeconds(self::MARGIN_SECONDS))->orWhere('refresh_expires_at', '>', now()->addSeconds(self::MARGIN_SECONDS)))
            ->exists();
    }

    public static function forget(User $user): void
    {
        SkatteverketToken::query()->where('user_id', $user->id)->delete();
    }

    /**
     * @param  array<string, string>  $grant
     * @return array{access_token: string, expires_in: int, refresh_token: ?string, scope: string}
     */
    private static function token(array $grant): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(30)->post(Skatteverket::personAuthorizationServer().'/token', [
                ...$grant,
                'client_id' => Skatteverket::oauthClientId(),
                'client_secret' => Skatteverket::oauthClientSecret(),
            ]);
        } catch (ConnectionException) {
            throw new SkatteverketFailed(503, __('Skatteverket svarar inte just nu. Försök igen om en stund.'));
        }

        $accessToken = $response->json('access_token');

        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            throw new SkatteverketFailed($response->status(), __('Inloggningen hos Skatteverket gick inte att slutföra. Försök igen.'), is_string($response->json('error')) ? $response->json('error') : null);
        }

        $refresh = $response->json('refresh_token');

        return [
            'access_token' => $accessToken,
            'expires_in' => max(0, (int) ($response->json('expires_in') ?? 3600)),
            'refresh_token' => is_string($refresh) && $refresh !== '' ? $refresh : null,
            'scope' => (string) ($response->json('scope') ?? implode(' ', Skatteverket::SCOPES)),
        ];
    }

    /** @param  array{access_token: string, expires_in: int, refresh_token: ?string, scope: string}  $token */
    private static function store(User $user, array $token): SkatteverketToken
    {
        $now = CarbonImmutable::now();

        return SkatteverketToken::query()->updateOrCreate(['user_id' => $user->id], [
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'scope' => mb_substr($token['scope'], 0, 255),
            'expires_at' => $now->addSeconds($token['expires_in']),
            'refresh_expires_at' => $token['refresh_token'] === null ? null : $now->addMinutes(self::REFRESH_MINUTES),
        ]);
    }
}
