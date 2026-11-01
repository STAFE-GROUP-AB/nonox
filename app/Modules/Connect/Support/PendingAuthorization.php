<?php

declare(strict_types=1);

namespace App\Modules\Connect\Support;

use Illuminate\Support\Str;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\User;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;

/**
 * An app's validated authorization request, waiting in the session for the
 * person to approve or deny it (ADR-032). Addressed by a random token and
 * valid for ten minutes; taken out once.
 */
final class PendingAuthorization
{
    private const string KEY = 'connect.pending';

    private const int TTL_SECONDS = 600;

    public static function put(AuthorizationRequestInterface $authRequest): string
    {
        $token = Str::random(40);
        session()->put(self::KEY.'.'.$token, ['request' => serialize($authRequest), 'expires_at' => time() + self::TTL_SECONDS]);

        return $token;
    }

    public static function get(string $token): ?AuthorizationRequestInterface
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return null;
        }

        $pending = session()->get(self::KEY.'.'.$token);

        if (! is_array($pending) || ! is_string($pending['request'] ?? null) || (int) ($pending['expires_at'] ?? 0) < time()) {
            return null;
        }

        $authRequest = unserialize($pending['request'], ['allowed_classes' => [AuthorizationRequest::class, Client::class, Scope::class, User::class]]);

        return $authRequest instanceof AuthorizationRequestInterface ? $authRequest : null;
    }

    public static function forget(string $token): void
    {
        session()->forget(self::KEY.'.'.$token);
    }
}
