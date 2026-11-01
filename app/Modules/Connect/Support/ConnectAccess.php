<?php

declare(strict_types=1);

namespace App\Modules\Connect\Support;

/**
 * The API credential of the current request, if any (ADR-032). Held in a
 * scoped container binding, so it is forgotten between requests and jobs.
 *
 * User::hasTeamPermission() asks restricts() first: while an API credential
 * is in use, a permission is granted only when the credential has the
 * scope, for its own company, and the user has the permission. That makes
 * "scopes ∩ the user's current permissions" hold for every check in every
 * Action and Policy, not only for the ones the route names.
 */
final class ConnectAccess
{
    private ?ConnectCredential $credential = null;

    public static function current(): ?ConnectCredential
    {
        return app()->bound(self::class) ? app(self::class)->credential : null;
    }

    public static function set(?ConnectCredential $credential): void
    {
        app(self::class)->credential = $credential;
    }

    /** Whether the current API credential forbids this permission check. */
    public static function restricts(mixed $team, string $permission): bool
    {
        $credential = self::current();

        return $credential !== null && ! $credential->permits($team, $permission);
    }
}
