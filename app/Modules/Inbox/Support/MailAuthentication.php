<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

use App\Modules\Inbox\Domain\Channel;

/**
 * Whether the sender's domain vouches for a message (SPF, DKIM, DMARC) and
 * whether it was flagged as spam, as the receiving server saw it.
 *
 * In the cloud, Mailgun's results are read (X-Mailgun-Spf,
 * X-Mailgun-Dkim-Check-Result, X-Mailgun-Sflag, and an
 * Authentication-Results header when present). From an IMAP mailbox, the
 * Authentication-Results header the company's own mail server added is
 * read: the topmost one, or the one whose authserv-id is configured, since
 * a sender can write such headers further down themselves.
 *
 * A message fails when DMARC fails, or when DMARC gave no answer and
 * neither SPF nor DKIM passed. A failed message is quarantined even when
 * the sender is on the allowlist, since a From address alone is easily
 * forged.
 */
final class MailAuthentication
{
    /**
     * @param  array<string, string>  $transport  values the transport gave beside the message (Mailgun's POST fields)
     * @return array{spf: string, dkim: string, dmarc: string, spam: bool, failed: bool, source: string}
     */
    public static function evaluate(ParsedMail $mail, Channel $channel, array $transport = [], ?string $authservId = null): array
    {
        $results = ['spf' => 'none', 'dkim' => 'none', 'dmarc' => 'none'];
        $spam = false;

        $authenticationResults = self::authenticationResults($mail, $authservId);

        foreach (array_keys($results) as $method) {
            if ($authenticationResults !== null && preg_match('/\b'.$method.'=([a-z]+)/i', $authenticationResults, $match) === 1) {
                $results[$method] = strtolower($match[1]);
            }
        }

        if ($channel === Channel::Mailgun) {
            $spf = $transport['X-Mailgun-Spf'] ?? $mail->header('X-Mailgun-Spf');
            $dkim = $transport['X-Mailgun-Dkim-Check-Result'] ?? $mail->header('X-Mailgun-Dkim-Check-Result');
            $flag = $transport['X-Mailgun-Sflag'] ?? $mail->header('X-Mailgun-Sflag');

            if (is_string($spf) && $spf !== '') {
                $results['spf'] = strtolower(trim($spf));
            }

            if (is_string($dkim) && $dkim !== '') {
                $results['dkim'] = strtolower(trim($dkim));
            }

            $spam = is_string($flag) && strtolower(trim($flag)) === 'yes';
        } else {
            $spam = strtolower(trim((string) $mail->header('X-Spam-Flag'))) === 'yes';
        }

        $failed = $results['dmarc'] === 'fail'
            || ($results['dmarc'] !== 'pass' && $results['spf'] !== 'pass' && $results['dkim'] !== 'pass');

        return [...$results, 'spam' => $spam, 'failed' => $failed, 'source' => $channel->value];
    }

    /**
     * Why the message failed, in Swedish.
     *
     * @param  array<string, mixed>  $auth
     */
    public static function explain(array $auth): string
    {
        if (($auth['dmarc'] ?? null) === 'fail') {
            return __('Avsändarens domän underkände meddelandet (DMARC). Det kan vara förfalskat.');
        }

        return __('Avsändarens domän kunde inte bekräfta meddelandet (SPF :spf, DKIM :dkim, DMARC :dmarc). Det kan vara förfalskat.', [
            'spf' => (string) ($auth['spf'] ?? 'none'),
            'dkim' => (string) ($auth['dkim'] ?? 'none'),
            'dmarc' => (string) ($auth['dmarc'] ?? 'none'),
        ]);
    }

    private static function authenticationResults(ParsedMail $mail, ?string $authservId): ?string
    {
        $headers = $mail->headerAll('Authentication-Results');

        if ($authservId === null || $authservId === '') {
            return $headers[0] ?? null;
        }

        foreach ($headers as $header) {
            if (str_starts_with(strtolower(ltrim($header)), strtolower($authservId))) {
                return $header;
            }
        }

        return null;
    }
}
