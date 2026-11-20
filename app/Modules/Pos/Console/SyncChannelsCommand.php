<?php

declare(strict_types=1);

namespace App\Modules\Pos\Console;

use App\Modules\ModuleManager;
use App\Modules\Pos\Actions\SyncProducts;
use App\Modules\Pos\Domain\ConnectionStatus;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\PosModule;
use App\Modules\Pos\Support\PosApiFailed;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

/**
 * Pushes the till prices of ADR-082 (§10): the POS channel's CHANNEL list and
 * campaigns change on a date, not when someone saves, so every night just
 * after midnight each connected till gets the articles whose price today
 * differs from what it was last sent. Idempotent: SyncProducts keeps a hash
 * of what was sent per article, so an unchanged price is not sent again.
 */
class SyncChannelsCommand extends Command
{
    /** Never more rounds than this per connection; each round sends SyncProducts::BUDGET articles. */
    private const int MAX_ROUNDS = 50;

    protected $signature = 'pricing:sync-channels
        {--company= : Only this company id}';

    protected $description = 'Send changed channel and campaign prices to connected tills';

    public function handle(ModuleManager $modules, SyncProducts $sync): int
    {
        $companies = $this->option('company') !== null ? [(int) $this->option('company')] : $modules->companiesWith(PosModule::KEY);
        $sent = 0;

        foreach ($companies as $companyId) {
            if (! $modules->isEnabled(PosModule::KEY, $companyId)) {
                continue;
            }

            $connections = CurrentCompany::run($companyId, fn () => PosConnection::query()->where('status', ConnectionStatus::Active->value)->get());

            foreach ($connections as $connection) {
                if (! $connection->pushesProducts()) {
                    continue;
                }

                $this->info("Syncing prices to {$connection->provider->value} connection `{$connection->id}` of company `{$companyId}`...");

                try {
                    for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
                        $result = $sync($connection);
                        $sent += $result['sent'];

                        if (! $result['more']) {
                            break;
                        }
                    }
                } catch (PosApiFailed|ConnectionException $exception) {
                    CurrentCompany::run($companyId, function () use ($connection, $exception): void {
                        $connection->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 500)])->save();
                        $connection->log('error', __('Priserna kunde inte skickas till kassan. NONOX försöker igen nästa natt.'));
                    });
                    $this->warn("  failed: {$exception->getMessage()}");
                }
            }
        }

        $this->comment("Sent {$sent} articles.");

        return self::SUCCESS;
    }
}
