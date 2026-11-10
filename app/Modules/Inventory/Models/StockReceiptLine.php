<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Article;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $stock_receipt_id
 * @property int $article_id
 * @property ?int $location_id
 * @property int $ordered_milli
 * @property int $received_milli
 * @property int $unit_cost_ore
 * @property int $position
 * @property ?int $purchase_order_row_id
 */
class StockReceiptLine extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'stock_receipt_id',
        'article_id',
        'location_id',
        'ordered_milli',
        'received_milli',
        'unit_cost_ore',
        'position',
        'purchase_order_row_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ordered_milli' => 'integer',
            'received_milli' => 'integer',
            'unit_cost_ore' => 'integer',
            'position' => 'integer',
        ];
    }

    public function remainingMilli(): int
    {
        return max(0, $this->ordered_milli - $this->received_milli);
    }

    /** @return BelongsTo<StockReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StockReceipt::class, 'stock_receipt_id');
    }

    /** @return BelongsTo<PurchaseOrderRow, $this> */
    public function purchaseOrderRow(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderRow::class);
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
}
