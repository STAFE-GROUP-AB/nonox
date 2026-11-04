<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Models\MailingLink;

/**
 * The secrets in a mailing's links (ADR-048).
 *
 * - The **unsubscribe token** is personal: an HMAC of the mailing and the
 *   person with a key derived from APP_KEY. It is computable when each
 *   batch is sent, so only its SHA-256 is stored (token_hash) and a
 *   database leak gives no working link. It is used for unsubscribing and
 *   for nothing else, never for statistics.
 * - A **click link** identifies no one: `/utskick/lank/{link}/{sig}` with
 *   an HMAC of the link id, the same address for every recipient
 *   (second review round: LEK 9 kap. 28 §, ePrivacy art. 5(3), EDPB
 *   Guidelines 2/2023 on tracked URLs). The signature only proves the link
 *   was made by NONOX; the redirect goes to the URL stored for that link.
 *
 * Signatures and tokens are also checked under keys derived from
 * APP_PREVIOUS_KEYS, so sent mail keeps working after a key rotation.
 */
final class MailingTokens
{
    public static function token(int $mailingId, int $personId): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', "crm-mailing-recipient:{$mailingId}:{$personId}", self::keys()[0], true)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function signature(int $linkId): string
    {
        return substr(hash_hmac('sha256', "crm-mailing-link:{$linkId}", self::keys()[0]), 0, 32);
    }

    /** Whether $signature is right for the link, under the current key or one from APP_PREVIOUS_KEYS. */
    public static function validSignature(int $linkId, string $signature): bool
    {
        foreach (self::keys() as $key) {
            if (hash_equals(substr(hash_hmac('sha256', "crm-mailing-link:{$linkId}", $key), 0, 32), $signature)) {
                return true;
            }
        }

        return false;
    }

    public static function unsubscribeUrl(string $token): string
    {
        return route('crm.mailing.unsubscribe', ['token' => $token]);
    }

    public static function clickUrl(MailingLink $link): string
    {
        return route('crm.mailing.click', ['link' => $link->id, 'sig' => self::signature($link->id)]);
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
