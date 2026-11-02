<?php

declare(strict_types=1);

namespace App\Modules\Pos\Actions;

use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosProductLink;
use App\Modules\Pos\Models\PosStockPush;
use App\Modules\Pos\Support\PosApiFailed;
use App\Modules\Pos\Support\PosDrivers;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;

/**
 * Sends a connection's waiting stock changes to the till, oldest first,
 * each with the same id on every attempt. A change the till refuses for
 * good is marked FAILED and logged; a temporary failure (429, 5xx, no
 * answer) stops the run and is retried by the job with backoff.
 */
class PushStock
{
    public const int BATCH = 100;

    /** @return int changes sent */
    public function __invoke(PosConnection $connection): int
    {
        return CurrentCompany::run($connection->company_id, function () use ($connection): int {
            if (! $connection->isActive() || ! $connection->pushesStock()) {
                return 0;
            }

            $pushes = PosStockPush::query()->where('pos_connection_id', $connection->id)->where('status', PosStockPush::PENDING)->orderBy('id')->limit(self::BATCH)->get();
            $links = PosProductLink::query()->where('pos_connection_id', $connection->id)->whereIn('article_id', $pushes->pluck('article_id')->unique()->all())->with('article:id,name,number')->get()->keyBy('article_id');
            $driver = PosDrivers::for($connection->provider);
            $sent = 0;

            foreach ($pushes as $push) {
                $link = $links->get($push->article_id);

                if (! $link instanceof PosProductLink) {
                    $push->forceFill(['status' => PosStockPush::SKIPPED, 'last_error' => 'not linked'])->save();

                    continue;
                }

                $push->forceFill(['attempts' => $push->attempts + 1])->save();

                try {
                    $driver->pushStockChange($connection, $link, $push->delta_milli, $push->external_uuid);
                } catch (PosApiFailed $exception) {
                    $push->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 500)])->save();

                    if ($exception->retryable() && ! $exception->credentialsRejected) {
                        throw $exception;
                    }

                    $push->forceFill(['status' => PosStockPush::FAILED])->save();
                    $connection->log('error', __('Lagerändringen för :article kunde inte skickas till kassan.', ['article' => $link->article->name ?? $push->article_id]), ['status' => $exception->status]);

                    continue;
                }

                $push->forceFill(['status' => PosStockPush::SENT, 'sent_at' => now(), 'last_error' => null])->save();
                $sent++;
            }

            if ($sent > 0) {
                $connection->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();
                $connection->log('info', trans_choice(':count lagerändring skickades till kassan.|:count lagerändringar skickades till kassan.', $sent, ['count' => $sent]));
                Audit::record('pos.stock_pushed', $connection, ['count' => $sent], $connection->company_id);
            }

            return $sent;
        });
    }
}
