<?php

declare(strict_types=1);

namespace App\Modules\Connect\OAuth;

/**
 * The grant that the code or token being issued on this request belongs to
 * (ADR-032). Set when a consent is approved, when an authorization code is
 * redeemed and when a refresh token is used; read when the new code or
 * token is stored. Scoped to the request.
 */
final class GrantContext
{
    public ?string $grantId = null;
}
