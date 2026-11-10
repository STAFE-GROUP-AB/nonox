<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Domain\Units\UnitConverter;
use App\Models\Article;
use App\Models\Unit;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row on a purchase order (ADR-071 §2, §12.2): the quantity as typed in
 * the row's unit, the same quantity in milli-units of the article's stock
 * unit, the price per the row's unit, and what goods receipts have ticked
 * off (in the stock unit).
 *
 * @property int $id
 * @property int $company_id
 * @property int $purchase_order_id
 * @property int $position
 * @property int $article_id
 * @property ?string $supplier_article_number
 * @property string $description
 * @property string $entered_quantity
 * @property int $unit_id
 * @property int $quantity_milli
 * @property int $price_ore
 * @property ?CarbonImmutable $expected_on
 * @property int $received_milli
 */
class PurchaseOrderRow extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'purchase_order_id',
        'position',
        'article_id',
        'supplier_article_number',
        'description',
        'entered_quantity',
        'unit_id',
        'quantity_milli',
        'price_ore',
        'expected_on',
        'received_milli',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity_milli' => 'integer',
            'price_ore' => 'integer',
            'received_milli' => 'integer',
            'expected_on' => 'immutable_date',
        ];
    }

    /** The row's value: the typed quantity × the price per the row's unit, half up once (§12.4). */
    public function valueOre(): int
    {
        return UnitConverter::lineValueOre($this->entered_quantity, $this->price_ore);
    }

    /** Cost per whole stock unit, for the goods receipt (F-U1c, F-U5). */
    public function stockUnitCostOre(): int
    {
        return UnitConverter::unitCostOre($this->valueOre(), $this->quantity_milli);
    }

    public function remainingMilli(): int
    {
        return max(0, $this->quantity_milli - $this->received_milli);
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
