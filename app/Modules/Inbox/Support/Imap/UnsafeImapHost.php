<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support\Imap;

/**
 * The IMAP server's name is not one NONOX connects to: it is malformed or
 * a disguised number, it does not resolve, or it points at a loopback,
 * private, link-local, reserved or multicast address (ADR-023).
 */
final class UnsafeImapHost extends MailboxUnavailable
{
    public const string INVALID = 'invalid';

    public const string UNRESOLVABLE = 'unresolvable';

    public const string INTERNAL = 'internal';

    public const string PORT = 'port';

    public function __construct(public readonly string $reason, string $host)
    {
        parent::__construct("The IMAP host {$host} was refused: {$reason}.");
    }

    /** What the person who entered the server is told, in Swedish. */
    public function explain(): string
    {
        return match ($this->reason) {
            self::INVALID => __('Skriv serverns namn, till exempel imap.exempel.se.'),
            self::UNRESOLVABLE => __('Servern kunde inte hittas. Kontrollera namnet.'),
            self::PORT => __('NONOX ansluter bara till port 993 (TLS) eller 143 (STARTTLS).'),
            default => __('Servern har en intern adress. NONOX hämtar bara e-post från servrar på internet.'),
        };
    }
}
