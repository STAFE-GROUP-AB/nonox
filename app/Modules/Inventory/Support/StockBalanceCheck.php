<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Models\Company;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\Tenancy\CurrentCompany;

/**
 * Compares stock_levels with the sum of stock_movements per article and
 * location. Two queries.
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
}
