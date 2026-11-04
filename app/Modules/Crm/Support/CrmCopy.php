<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

/**
 * For a self-hosted NONOX that reads its inbox over IMAP (ADR-023): whether
 * a fetched message was sent to the mailbox's +crm address (ADR-048), such
 * as bokforing+crm@firma.se for the mailbox bokforing@firma.se. Most mail
 * servers deliver plus addresses to the main mailbox.
 *
 * A BCC copy is not in To or Cc, so the envelope headers the receiving
 * server adds (Delivered-To, X-Original-To, Envelope-To) are read first;
 * To and Cc cover a forward written to the address. Only the headers at
 * the top of the message are read, before the first blank line.
 */
final class CrmCopy
{
    private const array HEADERS = ['delivered-to', 'x-original-to', 'envelope-to', 'x-envelope-to', 'to', 'cc'];

    public static function isFor(string $raw, string $mailboxUsername): bool
    {
        $address = mb_strtolower(trim($mailboxUsername));
        $at = strrpos($address, '@');
        $pattern = $at === false
            ? '/(?:^|[\s<,:"])[^\s<>@,"]+\+crm@[^\s<>,"]+/i'
            : '/(?:^|[\s<,:"])'.preg_quote(substr($address, 0, $at), '/').'\+crm@'.preg_quote(substr($address, $at + 1), '/').'(?:$|[\s>,"])/i';

        $headerBlock = (preg_split('/\r?\n\r?\n/', $raw, 2) ?: [''])[0];
        // Unfold continuation lines so a long To header is one line.
        $headerBlock = (string) preg_replace('/\r?\n[ \t]+/', ' ', $headerBlock);

        foreach (preg_split('/\r?\n/', $headerBlock) ?: [] as $line) {
            $colon = strpos($line, ':');

            if ($colon === false || ! in_array(strtolower(trim(substr($line, 0, $colon))), self::HEADERS, true)) {
                continue;
            }

            // Without an e-mail address as the user name, only the envelope headers the server added count.
            if ($at === false && in_array(strtolower(trim(substr($line, 0, $colon))), ['to', 'cc'], true)) {
                continue;
            }

            if (preg_match($pattern, substr($line, $colon + 1)) === 1) {
                return true;
            }
        }

        return false;
    }
}
