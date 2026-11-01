<?php

declare(strict_types=1);

namespace App\Modules\Connect\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes what has no use any more (ADR-032): idempotency records past
 * their 24 hours, and expired authorization codes and tokens. Grants, API
 * keys and the audit log are kept.
 */
final class PruneConnectCommand extends Command
{
    protected $signature = 'connect:prune';

    protected $description = 'Remove expired idempotency records, authorization codes and tokens';

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

        $this->comment("Removed {$idempotency} idempotency records, {$codes} codes, {$refresh} refresh tokens and {$access} access tokens.");

        return self::SUCCESS;
    }
}
