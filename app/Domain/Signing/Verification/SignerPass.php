<?php

declare(strict_types=1);

namespace App\Domain\Signing\Verification;

use App\Domain\Signing\SigningToken;
use App\Models\SigningRequest;
use Illuminate\Contracts\Session\Session;

/**
 * A passed signer check, kept in the browser's session for thirty minutes
 * (ADR-046 amendment, gap 3): bound to the signer row and to the hash of
 * the token it was passed with, so a new browser, or another link to the
 * same signer, needs a new check. The session holds no code.
 */
final class SignerPass
{
    public const int MINUTES = 30;

    private const string KEY = 'signing.pass.';

    public static function grant(Session $session, SigningRequest $request, string $token): void
    {
        $session->put(self::KEY.$request->id, ['token' => SigningToken::hash($token), 'until' => now()->addMinutes(self::MINUTES)->getTimestamp()]);
    }

    /** Whether the page may show the document: no check needed, or a pass for this token that has not run out. */
    public static function has(Session $session, SigningRequest $request, string $token): bool
    {
        if (! $request->verification->required()) {
            return true;
        }

        $pass = $session->get(self::KEY.$request->id);

        if (! is_array($pass) || ! is_string($pass['token'] ?? null) || ! is_int($pass['until'] ?? null)) {
            return false;
        }

        return $request->verified_at !== null
            && $request->locked_at === null
            && hash_equals($pass['token'], SigningToken::hash($token))
            && $pass['until'] > now()->getTimestamp();
    }
}
