<?php

declare(strict_types=1);

namespace App\Modules\Connect\OAuth;

use App\Models\User;
use App\Modules\Connect\Models\ConnectGrant;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * Ends a grant and every code and token issued under it (ADR-032). Used when
 * a person revokes an app, when the app revokes its own token (RFC 7009),
 * when the module is turned off, and when a used
 * refresh token or authorization code comes back (a sign that it was
 * stolen: RFC 9700 §4.14.2 and RFC 6749 §4.1.2).
 */
final class GrantTokens
{
    public const string BY_USER = 'revoked_by_user';

    public const string REFRESH_TOKEN_REUSED = 'refresh_token_reused';

    public const string CODE_REUSED = 'authorization_code_reused';

    public const string MODULE_DISABLED = 'module_disabled';

    public const string MEMBER_LEFT = 'member_left';

    /** The app itself asked for its token to be revoked (RFC 7009). */
    public const string REVOKED_BY_APP = 'revoked_by_app';

    public static function revoke(string $grantId, string $reason, ?User $by = null): void
    {
        // Called from the token endpoint, where no company is current; the grant names its own company.
        $grant = CompanyScope::bypass(fn (): ?ConnectGrant => ConnectGrant::query()->whereKey($grantId)->lockForUpdate()->first());

        if ($grant === null) {
            return;
        }

        DB::table('oauth_auth_codes')->where('connect_grant_id', $grantId)->update(['revoked' => true]);
        DB::table('oauth_access_tokens')->where('connect_grant_id', $grantId)->update(['revoked' => true, 'updated_at' => now()]);
        DB::table('oauth_refresh_tokens')->where('connect_grant_id', $grantId)->update(['revoked' => true]);

        if ($grant->revoked_at !== null) {
            if ($reason !== self::BY_USER) {
                Audit::record('connect.grant.'.$reason, $grant, ['client_id' => $grant->client_id, 'already_revoked' => true], $grant->company_id, $by);
            }

            return;
        }

        // Not through Eloquent's update(): an archived company's grants must still be revocable.
        DB::table('connect_grants')->where('id', $grantId)->update([
            'revoked_at' => now(),
            'revoked_by' => $by?->id,
            'revoke_reason' => $reason,
            'updated_at' => now(),
        ]);

        Audit::record($reason === self::BY_USER ? 'connect.grant.revoked' : 'connect.grant.'.$reason, $grant, [
            'client_id' => $grant->client_id,
            'scopes' => $grant->scopes,
            'reason' => $reason,
        ], $grant->company_id, $by);
    }
}
