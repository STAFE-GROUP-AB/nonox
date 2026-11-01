<?php

declare(strict_types=1);

namespace App\Modules\Connect\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes what has no use any more (ADR-032): idempotency records past
 * their 24 hours, expired authorization codes and tokens, and webhook
 * deliveries finished more than 30 days ago. Grants, API keys, webhooks
 * and the audit log are kept.
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

        $this->comment("Removed {$idempotency} idempotency records, {$codes} codes, {$refresh} refresh tokens, {$access} access tokens and {$deliveries} webhook deliveries.");

        return self::SUCCESS;
    }
}
