<?php

declare(strict_types=1);

namespace App\Support\Skatteverket;

use App\Models\User;

/**
 * The installation's registration with Skatteverket's partner APIs
 * (ADR-054): which environment, and the hosts that belong to it. The test
 * environment ("Komplett testtjänst") inserts ".test" in every host name
 * (icc-common-external-securityschemes 1.0.3).
 */
final class Skatteverket
{
    /**
     * The scopes a sign-in asks for: only those of the stages that are built
     * (ADR-054). The registration also holds agd, agdredovisningperiod, ska
     * and kundhandelser; each is added here with its stage, never before
     * (GDPR art. 5.1 c, Allmänna villkor 4.2).
     */
    public const array SCOPES = ['momsdeklaration'];

    public static function isConfigured(): bool
    {
        foreach (['oauth_client_id', 'oauth_client_secret', 'api_client_id', 'api_client_secret'] as $key) {
            if ((string) config("services.skatteverket.{$key}") === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the person may use the integration: the keys are set and, when
     * the installation lists pilot users, the person is one of them. The
     * list lets the operator try the test service on a live installation
     * without showing it to anyone else (ADR-054).
     */
    public static function isAvailableTo(?User $user): bool
    {
        if (! self::isConfigured() || $user === null) {
            return false;
        }

        $pilots = self::pilotUsers();

        return $pilots === [] || in_array(mb_strtolower((string) $user->email), $pilots, true);
    }

    /** @return list<string> */
    public static function pilotUsers(): array
    {
        return array_values(array_filter(array_map(
            fn (string $email): string => mb_strtolower(trim($email)),
            explode(',', (string) config('services.skatteverket.pilot_users')),
        )));
    }

    public static function isTest(): bool
    {
        return config('services.skatteverket.environment') !== 'production';
    }

    /** The API gateway, e.g. https://api.test.skatteverket.se. */
    public static function apiHost(): string
    {
        return self::isTest() ? 'https://api.test.skatteverket.se' : 'https://api.skatteverket.se';
    }

    /** The authorization server for a person signing in with e-legitimation (ACG "per"). */
    public static function personAuthorizationServer(): string
    {
        return self::isTest() ? 'https://peroauth2.test.skatteverket.se/oauth2/v1/per' : 'https://peroauth2.skatteverket.se/oauth2/v1/per';
    }

    public static function oauthClientId(): string
    {
        return (string) config('services.skatteverket.oauth_client_id');
    }

    public static function oauthClientSecret(): string
    {
        return (string) config('services.skatteverket.oauth_client_secret');
    }

    public static function apiClientId(): string
    {
        return (string) config('services.skatteverket.api_client_id');
    }

    public static function apiClientSecret(): string
    {
        return (string) config('services.skatteverket.api_client_secret');
    }
}
