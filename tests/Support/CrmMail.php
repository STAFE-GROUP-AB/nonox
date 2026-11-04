<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Company;
use App\Modules\Inbox\Support\InboxAddress;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * CRM stage 5 in tests (ADR-048): synthetic messages for the +crm address,
 * with an Authentication-Results header from the receiving server the test
 * configuration trusts, and a signed Mailgun request carrying them.
 */
final class CrmMail
{
    public const string SIGNING_KEY = 'crm-test-signing-key-0123456789';

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     */
    public static function raw(string $from, array $to, string $subject, string $text, array $cc = [], ?string $fromName = null, bool $authenticated = true, ?string $attachment = null): string
    {
        $email = (new Email)
            ->from(new Address($from, $fromName ?? ''))
            ->to(...$to)
            ->subject($subject)
            ->text($text)
            ->date(new \DateTimeImmutable('2026-06-15 09:30:00+02:00'));

        if ($cc !== []) {
            $email->cc(...$cc);
        }

        if ($attachment !== null) {
            $email->attach($attachment, 'original.eml', 'message/rfc822');
        }

        $domain = substr($from, (int) strrpos($from, '@') + 1);
        $auth = $authenticated ? sprintf(Inbox::PASS, $domain) : sprintf(Inbox::DMARC_FAIL, $domain);

        return "Authentication-Results: {$auth}\r\n".$email->toString();
    }

    public static function address(Company $company): string
    {
        return InboxAddress::forSlug(InboxAddress::mailbox($company)->slug, InboxAddress::CRM_ALIAS);
    }

    /** @return array<string, string> a signed Mailgun inbound request */
    public static function request(string $raw, string $recipient): array
    {
        $timestamp = (string) now()->getTimestamp();
        $token = bin2hex(random_bytes(25));

        return [
            'timestamp' => $timestamp,
            'token' => $token,
            'signature' => hash_hmac('sha256', $timestamp.$token, self::SIGNING_KEY),
            'recipient' => $recipient,
            'body-mime' => $raw,
        ];
    }

    /**
     * A signed Mailgun event (delivered, failed, complained).
     *
     * @param  array<string, mixed>  $eventData
     * @return array<string, mixed>
     */
    public static function event(array $eventData): array
    {
        $timestamp = (string) now()->getTimestamp();
        $token = bin2hex(random_bytes(25));

        return [
            'signature' => ['timestamp' => $timestamp, 'token' => $token, 'signature' => hash_hmac('sha256', $timestamp.$token, self::SIGNING_KEY)],
            'event-data' => $eventData,
        ];
    }
}
