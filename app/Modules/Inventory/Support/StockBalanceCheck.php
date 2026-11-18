<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Models\Article;
use App\Models\Company;
use App\Modules\Inventory\Domain\LocationType;
use App\Modules\Inventory\Domain\Tracking;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockLotLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferLine;
use App\Support\Tenancy\CurrentCompany;

/**
 * Compares stock_levels with the sum of stock_movements per article and
 * location (two queries), and the lot balances of tracked articles with their
 * movements and with the article's balance (lotDifferences).
 */
final class StockBalanceCheck
{
    /** @return list<array{article_id: int, location_id: int, balance: int, movements: int}> */
    public static function differences(Company $company): array
    {
        return CurrentCompany::run($company, function (): array {
            $sums = StockMovement::query()->groupBy('article_id', 'location_id')
                ->selectRaw('article_id, location_id, SUM(quantity_milli) AS total')->get()
                ->mapWithKeys(fn (StockMovement $row): array => ["{$row->article_id}:{$row->location_id}" => (int) $row->getAttribute('total')]);
            $levels = StockLevel::query()->get(['article_id', 'location_id', 'quantity_milli'])
                ->mapWithKeys(fn (StockLevel $level): array => ["{$level->article_id}:{$level->location_id}" => $level->quantity_milli]);
            $differences = [];

            foreach ($sums->keys()->merge($levels->keys())->unique() as $key) {
                $balance = (int) ($levels[$key] ?? 0);
                $movements = (int) ($sums[$key] ?? 0);

                if ($balance !== $movements) {
                    [$article, $location] = array_map('intval', explode(':', (string) $key));
                    $differences[] = ['article_id' => $article, 'location_id' => $location, 'balance' => $balance, 'movements' => $movements];
                }
            }

            return $differences;
        });
    }

    /**
     * The same check for tracked articles (ADR-081 E12): every lot's balance at
     * a location is the sum of the lot's movements there, and a tracked
     * article's balance at a location is the sum of its lot balances. Four
     * queries. A line with a lot id compares the lot; one without compares the
     * article ("movements" then holds the sum of the lot balances).
     *
     * @return list<array{article_id: int, location_id: int, lot_id: ?int, balance: int, movements: int}>
     */
    public static function lotDifferences(Company $company): array
    {
        return CurrentCompany::run($company, function (): array {
            $differences = [];
            $sums = StockMovement::query()->whereNotNull('lot_id')->groupBy('article_id', 'location_id', 'lot_id')
                ->selectRaw('article_id, location_id, lot_id, SUM(quantity_milli) AS total')->get()
                ->mapWithKeys(fn (StockMovement $row): array => ["{$row->article_id}:{$row->location_id}:{$row->lot_id}" => (int) $row->getAttribute('total')]);
            $levels = StockLotLevel::query()->get(['article_id', 'location_id', 'lot_id', 'quantity_milli']);
            $byLot = $levels->mapWithKeys(fn (StockLotLevel $level): array => ["{$level->article_id}:{$level->location_id}:{$level->lot_id}" => $level->quantity_milli]);

            foreach ($sums->keys()->merge($byLot->keys())->unique() as $key) {
                $balance = (int) ($byLot[$key] ?? 0);
                $movements = (int) ($sums[$key] ?? 0);

                if ($balance !== $movements) {
                    [$article, $location, $lot] = array_map('intval', explode(':', (string) $key));
                    $differences[] = ['article_id' => $article, 'location_id' => $location, 'lot_id' => $lot, 'balance' => $balance, 'movements' => $movements];
                }
            }

            $perArticle = [];

            foreach ($levels as $level) {
                $perArticle["{$level->article_id}:{$level->location_id}"] = ($perArticle["{$level->article_id}:{$level->location_id}"] ?? 0) + $level->quantity_milli;
            }

            $tracked = Article::query()->where('tracking', '<>', Tracking::None->value)->pluck('id');
            $stock = StockLevel::query()->whereIn('article_id', $tracked)->get(['article_id', 'location_id', 'quantity_milli'])
                ->mapWithKeys(fn (StockLevel $level): array => ["{$level->article_id}:{$level->location_id}" => $level->quantity_milli]);

            foreach ($stock->keys()->merge(array_keys($perArticle))->unique() as $key) {
                [$article, $location] = array_map('intval', explode(':', (string) $key));

                if (! $tracked->contains($article)) {
                    continue;
                }

                $balance = (int) ($stock[$key] ?? 0);
                $lots = (int) ($perArticle[$key] ?? 0);

                if ($balance !== $lots) {
                    $differences[] = ['article_id' => $article, 'location_id' => $location, 'lot_id' => null, 'balance' => $balance, 'movements' => $lots];
                }
            }

            return $differences;
        });
    }

    /**
     * Goods in transit (ADR-081 E13): what a transit location holds of an
     * article is what the open lines of the transfer orders on their way
     * through it still have to deliver. Two queries.
     *
     * @return list<array{article_id: int, location_id: int, balance: int, movements: int}>
     */
    public static function transitDifferences(Company $company): array
    {
        return CurrentCompany::run($company, function (): array {
            $transit = StockLocation::query()->where('type', LocationType::Transit->value)->pluck('id');

            if ($transit->isEmpty()) {
                return [];
            }

            $levels = StockLevel::query()->whereIn('location_id', $transit->all())->get(['article_id', 'location_id', 'quantity_milli'])
                ->mapWithKeys(fn (StockLevel $level): array => ["{$level->article_id}:{$level->location_id}" => $level->quantity_milli]);
            $open = StockTransferLine::query()
                ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_lines.stock_transfer_id')
                ->whereIn('stock_transfers.status', [StockTransfer::SENT, StockTransfer::PARTIALLY_RECEIVED])
                ->groupBy('stock_transfer_lines.article_id', 'stock_transfers.transit_location_id')
                ->selectRaw('stock_transfer_lines.article_id AS article_id, stock_transfers.transit_location_id AS location_id, SUM(quantity_milli - received_milli - returned_milli) AS total')
                ->get()
                ->mapWithKeys(fn (StockTransferLine $row): array => ["{$row->getAttribute('article_id')}:{$row->getAttribute('location_id')}" => (int) $row->getAttribute('total')]);
            $differences = [];

            foreach ($levels->keys()->merge($open->keys())->unique() as $key) {
                $balance = (int) ($levels[$key] ?? 0);
                $expected = (int) ($open[$key] ?? 0);

                if ($balance !== $expected) {
                    [$article, $location] = array_map('intval', explode(':', (string) $key));
                    $differences[] = ['article_id' => $article, 'location_id' => $location, 'balance' => $balance, 'movements' => $expected];
                }
            }

            return $differences;
        });
    }
}
