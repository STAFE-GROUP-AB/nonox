<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Article;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One article (and, once sent, one batch or serial number) of a transfer
 * order. received_milli is what has arrived; returned_milli what went back to
 * the sender when the rest was cancelled.
 *
 * @property int $id
 * @property int $company_id
 * @property int $stock_transfer_id
 * @property int $article_id
 * @property ?int $lot_id
 * @property int $quantity_milli
 * @property int $received_milli
 * @property int $returned_milli
 * @property int $position
 */
class StockTransferLine extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'stock_transfer_id',
        'article_id',
        'lot_id',
        'quantity_milli',
        'received_milli',
        'returned_milli',
        'position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'received_milli' => 'integer',
            'returned_milli' => 'integer',
        ];
    }

    /** What is still on its way. */
    public function openMilli(): int
    {
        return $this->quantity_milli - $this->received_milli - $this->returned_milli;
    }

    /** @return BelongsTo<StockTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<StockLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }
}
