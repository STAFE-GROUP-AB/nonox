<?php

declare(strict_types=1);

namespace App\Support\Skatteverket;

use App\Models\SkatteverketToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
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

    /** @param  list<string>  $scopes  the scopes to ask for (Skatteverket::scopesFor) */
    public static function authorizeUrl(string $state, string $redirectUri, array $scopes): string
    {
        return Skatteverket::personAuthorizationServer().'/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => Skatteverket::oauthClientId(),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            // TODO(provider-verify): Skatteverket allows several scopes in one token but does not state the separator; OAuth 2.0 (RFC 6749 3.3) uses a space. Confirm in the test service.
            'scope' => implode(' ', $scopes),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchanges the code from the callback and stores the person's tokens.
     * When Skatteverket's answer names no scopes, the token is taken to
     * hold the ones asked for.
     *
     * @param  list<string>  $scopes  the scopes the sign-in asked for
     */
    public static function exchange(User $user, string $code, string $redirectUri, array $scopes): SkatteverketToken
    {
        $token = self::token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        return self::store($user, [...$token, 'scope' => $token['scope'] ?? implode(' ', $scopes)]);
    }

    /**
     * The scopes a sign-in should ask for: the service's, and those of the
     * person's sign-in that is still usable, so signing in for one service
     * does not end the other (one token per person; ADR-054 stage 2).
     *
     * @return list<string>
     */
    public static function scopesToRequest(User $user, string $service): array
    {
        return array_values(array_intersect(Skatteverket::SCOPES, array_unique([...(self::grantedScopes($user) ?? []), ...Skatteverket::scopesFor($service)])));
    }

    /**
     * A valid access token for the person, refreshed when it has run out and
     * the refresh token still lives; null means: sign in with Skatteverket.
     */
    public static function accessToken(User $user, ?string $scope = null): ?string
    {
        $token = SkatteverketToken::query()->where('user_id', $user->id)->first();

        if ($token === null || ! self::covers($token, $scope)) {
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

        // One refresh at a time per person: a parallel request waits and reuses the new token. The lock outlives the 30-second token request.
        try {
            return Cache::lock("skatteverket:refresh:{$user->id}", 45)->block(35, fn (): ?string => self::refresh($user));
        } catch (LockTimeoutException) {
            throw new SkatteverketFailed(503, __('Skatteverket svarar inte just nu. Försök igen om en stund.'));
        }
    }

    private static function refresh(User $user): ?string
    {
        $token = SkatteverketToken::query()->where('user_id', $user->id)->first();

        if ($token === null) {
            return null;
        }

        if ($token->expires_at->subSeconds(self::MARGIN_SECONDS)->isFuture()) {
            return $token->access_token;
        }

        try {
            // TODO(provider-verify): the refresh request is not described by Skatteverket; this is the OAuth 2.0 form (RFC 6749 6). Confirm in the test service.
            $refreshed = self::token(['grant_type' => 'refresh_token', 'refresh_token' => (string) $token->refresh_token]);

            // A refreshed token without a scope answer keeps the scopes of the sign-in.
            return self::store($user, [...$refreshed, 'scope' => $refreshed['scope'] ?? $token->scope], $token->refresh_expires_at)->access_token;
        } catch (SkatteverketFailed $exception) {
            // A refused refresh ends the sign-in; an outage at Skatteverket does not.
            if (in_array($exception->status, [400, 401], true)) {
                $token->delete();

                return null;
            }

            throw $exception;
        }
    }

    /**
     * Whether the person's sign-in can still be used, and, with a scope, also
     * for that API: a sign-in made before a stage was built lacks the stage's
     * scope and counts as not signed in for it, so the person signs in again.
     */
    public static function isSignedIn(User $user, ?string $scope = null): bool
    {
        $granted = self::grantedScopes($user);

        return $granted !== null && ($scope === null || in_array($scope, $granted, true));
    }

    /**
     * The scopes of the person's sign-in that can still be used, or null
     * when there is none. One query, for pages that check several scopes.
     *
     * @return ?list<string>
     */
    public static function grantedScopes(User $user): ?array
    {
        $token = SkatteverketToken::query()->where('user_id', $user->id)
            ->where(fn ($query) => $query->where('expires_at', '>', now()->addSeconds(self::MARGIN_SECONDS))->orWhere('refresh_expires_at', '>', now()->addSeconds(self::MARGIN_SECONDS)))
            ->first();

        return $token === null ? null : self::scopesOf($token);
    }

    /**
     * Whether a sign-in covers the scope. A sign-in that holds a still usable
     * token but lacks the scope is not used for that API.
     */
    public static function covers(SkatteverketToken $token, ?string $scope): bool
    {
        if ($scope === null) {
            return true;
        }

        return in_array($scope, self::scopesOf($token), true);
    }

    /** @return list<string> */
    private static function scopesOf(SkatteverketToken $token): array
    {
        // TODO(provider-verify): Skatteverket's token answer is assumed to name the granted scopes like OAuth 2.0 (RFC 6749 5.1, separated by spaces); a comma is accepted too. Confirm in the test service.
        return preg_split('/[\s,]+/', trim($token->scope), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public static function forget(User $user): void
    {
        SkatteverketToken::query()->where('user_id', $user->id)->delete();
    }

    /**
     * @param  array<string, string>  $grant
     * @return array{access_token: string, expires_in: int, refresh_token: ?string, scope: ?string}
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
            'scope' => is_string($response->json('scope')) && trim($response->json('scope')) !== '' ? $response->json('scope') : null,
        ];
    }

    /**
     * @param  array{access_token: string, expires_in: int, refresh_token: ?string, scope: string}  $token
     * @param  ?CarbonImmutable  $refreshUntil  on a refresh: the end set at sign-in, never extended
     */
    private static function store(User $user, array $token, ?CarbonImmutable $refreshUntil = null): SkatteverketToken
    {
        $now = CarbonImmutable::now();

        return SkatteverketToken::query()->updateOrCreate(['user_id' => $user->id], [
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'scope' => mb_substr($token['scope'], 0, 255),
            'expires_at' => $now->addSeconds($token['expires_in']),
            // TODO(provider-verify): whether a refreshed token's lifetime restarts; until confirmed, the sign-in's end is kept.
            'refresh_expires_at' => $token['refresh_token'] === null ? null : ($refreshUntil ?? $now->addMinutes(self::REFRESH_MINUTES)),
        ]);
    }
}
