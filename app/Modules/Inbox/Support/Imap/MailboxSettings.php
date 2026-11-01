<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support\Imap;

use Illuminate\Support\Facades\Crypt;

/**
 * Where and how to log in to the company's IMAP mailbox. The password is
 * stored encrypted in the module settings ("imap_secret") and only
 * decrypted here, when a fetch runs.
 */
final readonly class MailboxSettings
{
    /**
     * IMAP over TLS (port 993) or STARTTLS (port 143). There is no plain
     * connection: the password and the mail would cross the network in
     * clear text. Settings saved before 2026-11-01 with "tls" (implicit TLS
     * in webklex) or "none" are read as "ssl".
     */
    public const array ENCRYPTIONS = ['ssl', 'starttls'];

    public function __construct(
        public string $host,
        public int $port,
        public string $encryption,
        public string $username,
        public string $password,
        public string $folder,
    ) {}

    /**
     * @param  array<string, mixed>  $settings  the inbox module's settings
     */
    public static function fromModuleSettings(array $settings): ?self
    {
        if (! (bool) ($settings['imap_enabled'] ?? false) || blank($settings['imap_host'] ?? null) || blank($settings['imap_username'] ?? null) || blank($settings['imap_secret'] ?? null)) {
            return null;
        }

        return new self(
            host: (string) $settings['imap_host'],
            port: (int) ($settings['imap_port'] ?? 993),
            encryption: ($settings['imap_encryption'] ?? null) === 'starttls' ? 'starttls' : 'ssl',
            username: (string) $settings['imap_username'],
            password: Crypt::decryptString((string) $settings['imap_secret']),
            folder: filled($settings['imap_folder'] ?? null) ? (string) $settings['imap_folder'] : 'INBOX',
        );
    }
}
