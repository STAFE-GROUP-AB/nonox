<?php

declare(strict_types=1);

namespace App\Modules\Portal\Support;

use App\Modules\Portal\Models\PortalIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The portal's sign-in session (ADR-075 §8), on its own cookie
 * (UsePortalSession): started after a correct code with a new session id;
 * ended after 30 minutes idle, 12 hours in all, at "Logga ut", or when it
 * started before the identity's sessions_valid_from.
 */
final class PortalSession
{
    public const string STARTED = 'portal.started_at';

    public const string LAST_ACTIVITY = 'portal.last_activity';

    public const string CONTACT = 'portal.contact_id';

    public const string PENDING_EMAIL = 'portal.pending_email';

    public const string BRAND_KEY = 'portal.brand_key';

    public const string INVITATION = 'portal.invitation';

    public const int IDLE_MINUTES = 30;

    public const int ABSOLUTE_HOURS = 12;

    public static function start(Request $request, PortalIdentity $identity, ?int $contactId = null): void
    {
        Auth::guard('portal')->login($identity);
        $request->session()->regenerate();
        $request->session()->put([self::STARTED => now()->getTimestamp(), self::LAST_ACTIVITY => now()->getTimestamp()]);
        $request->session()->forget([self::PENDING_EMAIL, self::INVITATION]);

        if ($contactId !== null) {
            $request->session()->put(self::CONTACT, $contactId);
        }

        $identity->forceFill(['last_login_at' => now()])->save();
    }

    /** Whether the signed-in session has run out; touches the idle clock when it has not. */
    public static function expired(Request $request, PortalIdentity $identity): bool
    {
        $started = $request->session()->get(self::STARTED);
        $last = $request->session()->get(self::LAST_ACTIVITY);
        $now = now()->getTimestamp();

        if (! is_int($started) || ! is_int($last)
            || $started < $now - self::ABSOLUTE_HOURS * 3600
            || $last < $now - self::IDLE_MINUTES * 60
            || ($identity->sessions_valid_from !== null && $started < $identity->sessions_valid_from->getTimestamp())) {
            return true;
        }

        $request->session()->put(self::LAST_ACTIVITY, $now);

        return false;
    }

    public static function end(Request $request): void
    {
        Auth::guard('portal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
