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
    public const array ENCRYPTIONS = ['ssl', 'tls', 'none'];

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
            encryption: in_array($settings['imap_encryption'] ?? null, self::ENCRYPTIONS, true) ? (string) $settings['imap_encryption'] : 'ssl',
            username: (string) $settings['imap_username'],
            password: Crypt::decryptString((string) $settings['imap_secret']),
            folder: filled($settings['imap_folder'] ?? null) ? (string) $settings['imap_folder'] : 'INBOX',
        );
    }
}
