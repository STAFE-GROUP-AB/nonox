<?php

declare(strict_types=1);

namespace App\Modules\Connect\OAuth;

use Illuminate\Support\Facades\DB;

/**
 * Lookups of grants from the OAuth endpoints, where no company is current.
 */
final class Grants
{
    public static function isActive(string $grantId): bool
    {
        return DB::table('connect_grants')->where('id', $grantId)->whereNull('revoked_at')->exists();
    }

    /** The grant an access token belongs to, or null. */
    public static function forAccessToken(string $tokenId): ?string
    {
        $grantId = DB::table('oauth_access_tokens')->where('id', $tokenId)->where('revoked', false)->value('connect_grant_id');

        return is_string($grantId) ? $grantId : null;
    }
}
