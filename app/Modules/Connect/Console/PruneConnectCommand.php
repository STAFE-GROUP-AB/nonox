<?php

declare(strict_types=1);

namespace App\Modules\Connect\Console;

use App\Modules\Connect\Sftp\SftpConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes what has no use any more (ADR-032): idempotency records past
 * their 24 hours, expired authorization codes and tokens, and webhook
 * deliveries finished more than 30 days ago. Grants, API keys, webhooks
 * and the audit log are kept.
 *
 * In the SFTP drops (ADR-032, "SFTP"), files put out for download are
 * removed after NONOX_SFTP_EXPORT_DAYS (default 7), and a file left in /in
 * for more than a day (its upload notice never reached NONOX) is removed
 * too: files in the drop are not encrypted and must not stay there.
 */
final class PruneConnectCommand extends Command
{
    protected $signature = 'connect:prune';

    protected $description = 'Remove expired idempotency records, authorization codes, tokens and old webhook deliveries';

    public const int DELIVERY_LOG_DAYS = 30;

    public function handle(): int
    {
        $this->info('Pruning expired idempotency records...');
        $idempotency = DB::table('connect_idempotency_keys')->where('expires_at', '<', now())->delete();

        $this->info('Pruning expired authorization codes and tokens...');
        $codes = DB::table('oauth_auth_codes')->where('expires_at', '<', now()->subDay())->delete();
        $refresh = DB::table('oauth_refresh_tokens')->where('expires_at', '<', now()->subDay())->delete();
        $access = DB::table('oauth_access_tokens')->where('expires_at', '<', now()->subDay())
            ->whereNotIn('id', DB::table('oauth_refresh_tokens')->select('access_token_id'))
            ->delete();

        $this->info('Pruning finished webhook deliveries...');
        $deliveries = DB::table('connect_webhook_deliveries')->where('status', '<>', 'pending')->where('created_at', '<', now()->subDays(self::DELIVERY_LOG_DAYS))->delete();

        $this->info('Pruning old files in the SFTP drops...');
        $files = $this->pruneSftpDrops();

        $this->comment("Removed {$idempotency} idempotency records, {$codes} codes, {$refresh} refresh tokens, {$access} access tokens, {$deliveries} webhook deliveries and {$files} SFTP files.");

        return self::SUCCESS;
    }

    private function pruneSftpDrops(): int
    {
        if (SftpConfig::root() === '' || ! is_dir(SftpConfig::root())) {
            return 0;
        }

        $removed = 0;
        $limits = [
            SftpConfig::OUTBOX => now()->subDays(SftpConfig::exportDays())->getTimestamp(),
            SftpConfig::INBOX => now()->subDay()->getTimestamp(),
        ];

        foreach (DB::table('connect_sftp_accounts')->orderBy('id')->pluck('username') as $username) {
            foreach ($limits as $folder => $before) {
                $directory = SftpConfig::home((string) $username).'/'.$folder;

                foreach (is_dir($directory) ? (scandir($directory) ?: []) : [] as $name) {
                    $path = $directory.'/'.$name;

                    if (is_file($path) && ! is_link($path) && (int) filemtime($path) < $before && @unlink($path)) {
                        $removed++;
                    }
                }
            }
        }

        return $removed;
    }
}
