<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

use App\Modules\Inbox\Domain\Channel;

/**
 * Whether the sender's domain vouches for a message (SPF, DKIM, DMARC) and
 * whether it was flagged as spam, as the receiving server saw it.
 *
 * In the cloud, only what Mailgun posted beside the message counts
 * (X-Mailgun-Spf, X-Mailgun-Dkim-Check-Result, X-Mailgun-Sflag and the
 * envelope sender), never headers inside the message: the sender writes
 * those and could write "dmarc=pass" (security review 2026-11-01). An
 * Authentication-Results header is read only when its authserv-id is
 * configured (services.inbox.mailgun_authserv_id). Mailgun gives no DMARC
 * answer, so alignment is checked here: a passing SPF counts only when the
 * envelope sender's domain matches the From domain, and a passing DKIM only
 * when every DKIM signature is for the From domain (a subdomain either way
 * counts, a different domain does not).
 *
 * From an IMAP mailbox, the Authentication-Results header the company's own
 * mail server added is read: the topmost one, or the one whose authserv-id
 * is configured, since a sender can write such headers further down.
 *
 * A message fails when DMARC fails, or when DMARC gave no answer and
 * neither SPF nor DKIM passed (aligned, in the cloud). A failed message is
 * quarantined even when the sender is on the allowlist, since a From
 * address alone is easily forged.
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

        if ($channel === Channel::Mailgun) {
            return self::mailgun($mail, $transport, $authservId);
        }

        $authenticationResults = self::authenticationResults($mail, $authservId);

        foreach (array_keys($results) as $method) {
            if ($authenticationResults !== null && preg_match('/\b'.$method.'=([a-z]+)/i', $authenticationResults, $match) === 1) {
                $results[$method] = strtolower($match[1]);
            }
        }

        $spam = strtolower(trim((string) $mail->header('X-Spam-Flag'))) === 'yes';

        $failed = $results['dmarc'] === 'fail'
            || ($results['dmarc'] !== 'pass' && $results['spf'] !== 'pass' && $results['dkim'] !== 'pass');

        return [...$results, 'spam' => $spam, 'failed' => $failed, 'source' => $channel->value];
    }

    /**
     * @param  array<string, string>  $transport
     * @return array{spf: string, dkim: string, dmarc: string, spam: bool, failed: bool, source: string}
     */
    private static function mailgun(ParsedMail $mail, array $transport, ?string $authservId): array
    {
        $authservId ??= (string) config('services.inbox.mailgun_authserv_id');
        $configured = $authservId !== '' ? self::authenticationResults($mail, $authservId) : null;

        if ($configured !== null) {
            $results = ['spf' => 'none', 'dkim' => 'none', 'dmarc' => 'none'];

            foreach (array_keys($results) as $method) {
                if (preg_match('/\b'.$method.'=([a-z]+)/i', $configured, $match) === 1) {
                    $results[$method] = strtolower($match[1]);
                }
            }
        } else {
            $spf = strtolower(trim($transport['X-Mailgun-Spf'] ?? '')) ?: 'none';
            $dkim = strtolower(trim($transport['X-Mailgun-Dkim-Check-Result'] ?? '')) ?: 'none';
            $fromDomain = $mail->fromDomain();
            $spfAligned = $spf === 'pass' && self::aligned(self::domainOf($transport['sender'] ?? ''), $fromDomain);
            $dkimAligned = $dkim === 'pass' && self::dkimAligned($mail, $fromDomain);
            $results = ['spf' => $spf, 'dkim' => $dkim, 'dmarc' => $spfAligned || $dkimAligned ? 'pass' : 'none'];
        }

        $failed = $results['dmarc'] === 'fail'
            || ($results['dmarc'] !== 'pass' && ($configured === null || ($results['spf'] !== 'pass' && $results['dkim'] !== 'pass')));
        $spam = strtolower(trim($transport['X-Mailgun-Sflag'] ?? '')) === 'yes';

        return [...$results, 'spam' => $spam, 'failed' => $failed, 'source' => Channel::Mailgun->value];
    }

    /** Every DKIM signature in the message is for the From domain. */
    private static function dkimAligned(ParsedMail $mail, string $fromDomain): bool
    {
        $signatures = $mail->headerAll('DKIM-Signature');

        if ($signatures === []) {
            return false;
        }

        foreach ($signatures as $signature) {
            if (preg_match('/(?:^|;)\s*d\s*=\s*([A-Za-z0-9.-]+)/', $signature, $match) !== 1 || ! self::aligned(strtolower($match[1]), $fromDomain)) {
                return false;
            }
        }

        return true;
    }

    private static function domainOf(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? '' : strtolower(trim(substr($address, $at + 1), " >\t"));
    }

    /** The same domain, or one a subdomain of the other. */
    private static function aligned(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        return $a === $b || str_ends_with($a, ".{$b}") || str_ends_with($b, ".{$a}");
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
