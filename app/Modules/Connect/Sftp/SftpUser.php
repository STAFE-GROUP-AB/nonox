<?php

declare(strict_types=1);

namespace App\Modules\Connect\Sftp;

use App\Modules\Connect\Models\ConnectSftpAccount;

/**
 * The virtual user returned to SFTPGo's external authentication hook
 * (SFTPGo v2.7, docs/external-auth and the User schema in its
 * openapi/openapi.yaml; ADR-032, "SFTP").
 *
 * The user is rebuilt from NONOX on every login (external_auth_cache_time
 * 0), so a removed key, a narrowed allowlist or a disabled account takes
 * effect at the next login. It can log in with a public key over SSH only,
 * list its home, upload SIE files to /in (no overwrite, delete or rename)
 * and download from /ut.
 */
final class SftpUser
{
    /** Every login method except a plain public key (SFTPGo's LoginMethods enum). */
    private const array DENIED_LOGIN_METHODS = [
        'password',
        'password-over-SSH',
        'keyboard-interactive',
        'publickey+password',
        'publickey+keyboard-interactive',
        'TLSCertificate',
        'TLSCertificate+password',
    ];

    private const int MAX_SESSIONS = 2;

    /** @return array<string, mixed> */
    public static function for(ConnectSftpAccount $account, SshPublicKey $key): array
    {
        $inbox = '/'.SftpConfig::INBOX;
        $outbox = '/'.SftpConfig::OUTBOX;

        return [
            'status' => 1,
            'username' => $account->username,
            'description' => "NONOX company {$account->company_id}",
            'home_dir' => SftpConfig::homeRoot().'/'.$account->username,
            'public_keys' => [$key->line()],
            'permissions' => [
                '/' => ['list'],
                $inbox => ['list', 'upload'],
                $outbox => ['list', 'download'],
            ],
            'max_sessions' => self::MAX_SESSIONS,
            // NONOX enforces the limits itself: the file size here and in the job, and a cap per hour on the upload hook.
            // SFTPGo's own quota is not used, because NONOX removes files after reading them and SFTPGo would never see the space freed.
            'quota_size' => 0,
            'quota_files' => 0,
            'filters' => [
                'allowed_ip' => self::cidrs($account->allowed_cidrs),
                'denied_login_methods' => self::DENIED_LOGIN_METHODS,
                'denied_protocols' => ['FTP', 'DAV', 'HTTP'],
                'file_patterns' => [
                    [
                        'path' => $inbox,
                        'allowed_patterns' => array_map(fn (string $extension): string => "*.{$extension}", SftpConfig::EXTENSIONS),
                        'deny_policy' => 0,
                    ],
                ],
                'max_upload_file_size' => SftpConfig::maxFileBytes(),
                'external_auth_cache_time' => 0,
                'start_directory' => $inbox,
            ],
        ];
    }

    /**
     * SFTPGo wants CIDR notation (RFC 4632 / RFC 4291), so a single address
     * becomes /32 or /128.
     *
     * @param  list<string>  $entries
     * @return list<string>
     */
    public static function cidrs(array $entries): array
    {
        return array_map(
            fn (string $entry): string => str_contains($entry, '/') ? $entry : $entry.(str_contains($entry, ':') ? '/128' : '/32'),
            $entries,
        );
    }
}
