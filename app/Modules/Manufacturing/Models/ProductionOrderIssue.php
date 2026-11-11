<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Models\Article;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A component taken out of stock for a production order (positive), or
 * put back when the order is cancelled (negative), with its stock movement
 * and the valuation cost that left or came back (ADR-071 §8). Append-only:
 * the work in progress on a past day is the sum of these up to that day.
 *
 * @property int $id
 * @property int $company_id
 * @property int $production_order_id
 * @property int $production_order_component_id
 * @property int $article_id
 * @property int $location_id
 * @property int $stock_movement_id
 * @property int $quantity_milli
 * @property int $value_ore
 * @property CarbonImmutable $issued_on
 * @property ?int $created_by
 * @property ?CarbonInterface $created_at
 */
class ProductionOrderIssue extends Model
{
    use BelongsToCompany;

    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'production_order_id',
        'production_order_component_id',
        'article_id',
        'location_id',
        'stock_movement_id',
        'quantity_milli',
        'value_ore',
        'issued_on',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'value_ore' => 'integer',
            'issued_on' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A production order issue is never changed.'));
        static::deleting(fn () => throw new LogicException('A production order issue is never deleted.'));
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
