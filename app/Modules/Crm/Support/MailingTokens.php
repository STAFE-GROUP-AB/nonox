<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Models\MailingLink;

/**
 * The secrets in a mailing recipient's links (ADR-048).
 *
 * - The recipient's token is an HMAC of the recipient id with a key
 *   derived from APP_KEY: unguessable, and computable when each batch is
 *   sent, so only its SHA-256 is stored (token_hash) and a database leak
 *   gives no working link.
 * - A click link also carries a signature over the token and the link id,
 *   so a recipient's token cannot be combined with another link id; the
 *   redirect itself only ever goes to the URL stored for that link of
 *   that recipient's mailing.
 */
final class MailingTokens
{
    public static function token(int $recipientId): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', "crm-mailing-recipient:{$recipientId}", self::key(), true)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function signature(string $token, int $linkId): string
    {
        return substr(hash_hmac('sha256', "crm-mailing-link:{$token}:{$linkId}", self::key()), 0, 32);
    }

    /** Whether $signature is right for the token and link, under the current key or one from APP_PREVIOUS_KEYS. */
    public static function validSignature(string $token, int $linkId, string $signature): bool
    {
        foreach (self::keys() as $key) {
            if (hash_equals(substr(hash_hmac('sha256', "crm-mailing-link:{$token}:{$linkId}", $key), 0, 32), $signature)) {
                return true;
            }
        }

        return false;
    }

    public static function unsubscribeUrl(string $token): string
    {
        return route('crm.mailing.unsubscribe', ['token' => $token]);
    }

    public static function clickUrl(string $token, MailingLink $link): string
    {
        return route('crm.mailing.click', ['token' => $token, 'link' => $link->id, 's' => self::signature($token, $link->id)]);
    }

    private static function key(): string
    {
        return self::keys()[0];
    }

    /** @return non-empty-list<string> the current key, then those derived from APP_PREVIOUS_KEYS */
    private static function keys(): array
    {
        $keys = [hash_hmac('sha256', 'nonox-crm-mailing', (string) config('app.key'))];

        foreach ((array) config('app.previous_keys', []) as $previous) {
            if (is_string($previous) && $previous !== '') {
                $keys[] = hash_hmac('sha256', 'nonox-crm-mailing', $previous);
            }
        }

        return $keys;
    }
}
