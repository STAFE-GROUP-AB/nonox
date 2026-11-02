<?php

declare(strict_types=1);

namespace App\Modules\Connect\Sftp;

use App\Actions\Accounting\AnalyseSieFile;
use Illuminate\Support\Str;

/**
 * How this installation reaches its SFTP server (ADR-032, "SFTP";
 * deploy/sftpgo/README.md). SFTP is offered only when everything below is
 * set: a hook secret of at least 32 characters, the host people connect
 * to, and the directory where the SFTP server keeps the users' homes as
 * NONOX sees it. Without them both hooks answer 404 and the panel says
 * SFTP is not set up.
 */
final class SftpConfig
{
    public const int MIN_SECRET_LENGTH = 32;

    /** Folder for files sent to NONOX, as the SFTP client sees it. */
    public const string INBOX = 'in';

    /** Folder for files NONOX puts out for download. */
    public const string OUTBOX = 'ut';

    /** @var list<string> */
    public const array EXTENSIONS = ['se', 'si', 'sie'];

    public const string USERNAME_PATTERN = '/^nnx[a-z0-9]{13}$/';

    public static function available(): bool
    {
        return self::secret() !== null && self::host() !== '' && self::root() !== '';
    }

    /** The shared secret SFTPGo sends with every hook call; null when missing or too short. */
    public static function secret(): ?string
    {
        $secret = (string) config('services.connect.sftp.hook_secret', '');

        return strlen($secret) >= self::MIN_SECRET_LENGTH ? $secret : null;
    }

    /** @return list<string> addresses or CIDR ranges the hooks may be called from (empty: any) */
    public static function hookIps(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('services.connect.sftp.hook_ips', '')))));
    }

    public static function host(): string
    {
        return trim((string) config('services.connect.sftp.host', ''));
    }

    public static function port(): int
    {
        return (int) config('services.connect.sftp.port', 2022);
    }

    /** @return list<string> the server's host key fingerprints, for people to check on first connection */
    public static function hostFingerprints(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('services.connect.sftp.host_fingerprints', '')))));
    }

    /** The users' homes as NONOX sees them (on the same machine, or a shared mount). */
    public static function root(): string
    {
        return rtrim((string) config('services.connect.sftp.root', ''), '/');
    }

    /** The users' homes as the SFTP server sees them; the same as root() unless it runs in a container. */
    public static function homeRoot(): string
    {
        $homeRoot = rtrim((string) config('services.connect.sftp.home_root', ''), '/');

        return $homeRoot !== '' ? $homeRoot : self::root();
    }

    /**
     * Where the source of the SFTPGo release in use is. SFTPGo's NOTICE
     * (additional terms under AGPL-3.0 section 7) asks a frontend that
     * configures it to credit the project, imply no endorsement and link
     * to the corresponding source; the SFTP panel does that.
     */
    public static function sftpgoSourceUrl(): string
    {
        $version = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) config('services.connect.sftp.sftpgo_version', 'v2.7.6')) ?: 'main';

        return "https://github.com/drakkan/sftpgo/tree/{$version}";
    }

    public static function maxFileBytes(): int
    {
        return AnalyseSieFile::MAX_KILOBYTES * 1024;
    }

    /** Days a file put out for download stays in the outbox. */
    public static function exportDays(): int
    {
        return max(1, (int) config('services.connect.sftp.export_days', 7));
    }

    public static function newUsername(): string
    {
        return 'nnx'.Str::lower(Str::random(13));
    }

    public static function home(string $username): string
    {
        return self::root().'/'.$username;
    }

    public static function inbox(string $username): string
    {
        return self::home($username).'/'.self::INBOX;
    }

    public static function outbox(string $username): string
    {
        return self::home($username).'/'.self::OUTBOX;
    }

    public static function hasAllowedExtension(string $fileName): bool
    {
        return in_array(Str::lower(pathinfo($fileName, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * Makes sure the home and its two folders exist, group-writable so that
     * both the SFTP server and NONOX (one shared group, see the deploy
     * notes) can work in them. False when they cannot be made.
     */
    public static function ensureHome(string $username): bool
    {
        if (self::root() === '' || preg_match(self::USERNAME_PATTERN, $username) !== 1 || ! is_dir(self::root())) {
            return false;
        }

        foreach ([self::home($username), self::inbox($username), self::outbox($username)] as $directory) {
            if (is_dir($directory)) {
                continue;
            }

            if (! @mkdir($directory, 02770) && ! is_dir($directory)) {
                return false;
            }

            // mkdir() applies the process umask; the group must be able to write.
            @chmod($directory, 02770);
        }

        return true;
    }
}
