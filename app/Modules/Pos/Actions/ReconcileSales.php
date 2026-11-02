<?php

declare(strict_types=1);

namespace App\Modules\Pos\Actions;

use App\Modules\Pos\Drivers\Zettle\ZettleDriver;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Support\PosDrivers;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fetches a period's sales from the till and records the ones not yet here
 * (ADR-042). The same RecordPosSale serves the webhook, so running it again
 * over the same days changes nothing. With dry run nothing is written. For
 * a till that reports card fees, the period's fees are fetched too.
 */
class ReconcileSales
{
    public function __construct(private readonly RecordPosSale $record) {}

    /** @return array{fetched: int, new: int, known: int, fees: int} */
    public function __invoke(PosConnection $connection, CarbonImmutable $from, CarbonImmutable $until, bool $dryRun = false): array
    {
        $driver = PosDrivers::for($connection->provider);
        $records = $driver->fetchSales($connection, $from, $until);
        $result = ['fetched' => 0, 'new' => 0, 'known' => 0, 'fees' => 0];

        foreach ($records as $raw) {
            $sale = $driver->parseSale($raw);

            if ($sale === null) {
                continue;
            }

            $result['fetched']++;

            if ($dryRun) {
                $exists = CurrentCompany::run($connection->company_id, fn (): bool => PosSale::query()->where('provider', $connection->provider->value)->where('external_id', $sale->externalId)->exists());
                $result[$exists ? 'known' : 'new']++;

                continue;
            }

            $result[($this->record)($connection, $sale, PosSale::RECONCILE) === null ? 'known' : 'new']++;
        }

        if ($driver instanceof ZettleDriver && ! $dryRun) {
            foreach ($driver->fetchFees($connection, $from, $until) as $fee) {
                $result['fees'] += DB::table('pos_fees')->insertOrIgnore([
                    'company_id' => $connection->company_id,
                    'pos_connection_id' => $connection->id,
                    'external_id' => mb_substr($fee['external_id'], 0, 150),
                    'type' => $fee['type'],
                    'business_date' => $fee['occurred_at']->setTimezone('Europe/Stockholm')->toDateString(),
                    'amount_ore' => $fee['amount_ore'],
                    'payload' => json_encode($fee['payload']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (! $dryRun) {
            CurrentCompany::run($connection->company_id, function () use ($connection, $result): void {
                $connection->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();

                if ($result['new'] > 0) {
                    $connection->log('info', __(':count nya försäljningar hämtades från kassan.', ['count' => $result['new']]));
                }
            });
        }

        return $result;
    }
}
