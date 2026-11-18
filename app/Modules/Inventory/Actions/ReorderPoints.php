<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Domain\LocationType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\ArticleLocationSetting;
use App\Modules\Inventory\Models\StockLocation;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Beställningspunkt per lager (ADR-081 E13): the reorder point and reorder
 * quantity of an article in one warehouse. As soon as an article has one,
 * the purchase proposal judges it warehouse by warehouse and ignores the
 * article's company-wide reorder point. Needs inventory:manage.
 */
class ReorderPoints
{
    use GuardsInventory;

    public function save(User $user, Company $company, Article $article, int $warehouseId, string $point, string $quantity): ArticleLocationSetting
    {
        $this->guard($user, $company, 'manage');

        return CurrentCompany::run($company, fn (): ArticleLocationSetting => DB::transaction(function () use ($user, $company, $article, $warehouseId, $point, $quantity): ArticleLocationSetting {
            $article = Article::query()->findOrFail($article->id);

            if (! $article->is_stock_item) {
                throw ValidationException::withMessages(['article' => __('Artikeln :number är inte lagerförd. Markera den som lagerförd först.', ['number' => $article->number])]);
            }

            $warehouse = StockLocation::query()->where('type', LocationType::Warehouse->value)->find($warehouseId)
                ?? throw ValidationException::withMessages(['warehouse_id' => __('Välj ett lager.')]);

            $pointMilli = $this->milli($point, 'point');

            if ($pointMilli === null) {
                throw ValidationException::withMessages(['point' => __('Ange beställningspunkten.')]);
            }

            $quantityMilli = $this->milli($quantity, 'quantity');

            $setting = ArticleLocationSetting::query()->where('article_id', $article->id)->where('warehouse_id', $warehouse->id)->first();
            $values = ['reorder_point_milli' => $pointMilli, 'reorder_quantity_milli' => $quantityMilli];

            if ($setting === null) {
                $setting = ArticleLocationSetting::create([...$values, 'article_id' => $article->id, 'warehouse_id' => $warehouse->id]);
            } else {
                $setting->update($values);
            }

            Audit::record('inventory.reorder_point_saved', $article, ['article' => $article->id, 'warehouse' => $warehouse->id], $company, $user);

            return $setting;
        }));
    }

    public function delete(User $user, Company $company, ArticleLocationSetting $setting): void
    {
        $this->guard($user, $company, 'manage');

        CurrentCompany::run($company, function () use ($user, $company, $setting): void {
            $setting = ArticleLocationSetting::query()->findOrFail($setting->id);
            $setting->delete();
            Audit::record('inventory.reorder_point_deleted', null, ['article' => $setting->article_id, 'warehouse' => $setting->warehouse_id], $company, $user);
        });
    }

    private function milli(string $raw, string $field): ?int
    {
        if (trim($raw) === '') {
            return null;
        }

        try {
            $milli = Quantity::parse($raw);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('Antalet går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
        }

        if ($milli < 0) {
            throw ValidationException::withMessages([$field => __('Antalet får inte vara negativt.')]);
        }

        return $milli;
    }
}
