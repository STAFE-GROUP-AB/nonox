<?php

declare(strict_types=1);

namespace App\Support\Branding;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The brand on the way in (ADR-030): someone who follows an invitation to a
 * client company meets the login page, and gets the login code, in the
 * accounting firm's brand. The invitation is only trusted from the signed
 * link the visitor followed (kept by Laravel as the intended address), never
 * from anything typed into the page, so the login page cannot be used to ask
 * which firm a company works with.
 */
final class InvitationBrand
{
    /** The invitation behind the signed link the visitor followed, while it is valid. */
    public static function invitation(): ?TeamInvitation
    {
        $url = session()->get('url.intended');

        if (! is_string($url) || preg_match('#/foretagsinbjudningar/(\d+)(\?|$)#', $url, $match) !== 1) {
            return null;
        }

        if (! URL::hasValidSignature(Request::create($url))) {
            return null;
        }

        $invitation = TeamInvitation::query()->with('team')->find((int) $match[1]);

        return $invitation instanceof TeamInvitation && ! $invitation->hasExpired() ? $invitation : null;
    }

    /** The firm's brand for the login page, or null. */
    public static function forLoginPage(): ?PresentedBrand
    {
        $invitation = self::invitation();

        return $invitation instanceof TeamInvitation ? FirmBranding::forInvitation($invitation) : null;
    }

    /**
     * The firm's brand for the login code sent to the address: the
     * invitation's when the code goes to the invited address, otherwise the
     * one the user meets in their current company. Only the address's owner
     * reads the email.
     */
    public static function forLoginCode(string $email): ?PresentedBrand
    {
        $invitation = self::invitation();

        if ($invitation instanceof TeamInvitation && User::normaliseEmail($invitation->email) === User::normaliseEmail($email)) {
            return FirmBranding::forInvitation($invitation);
        }

        $user = User::query()->byEmail($email)->whereNull('anonymised_at')->orderBy('id')->first();

        return $user instanceof User ? FirmBranding::forUser($user) : null;
    }
}
