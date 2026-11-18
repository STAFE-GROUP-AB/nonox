<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Article;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The quantity of one lot at one location (ADR-081 E12), kept by StockLedger
 * in the same transaction as the movement and checked by inventory:verify.
 * A serial number's level is 0 or 1000. The article's total stays in
 * stock_levels and equals the sum of its lot levels.
 *
 * @property int $id
 * @property int $company_id
 * @property int $article_id
 * @property int $location_id
 * @property int $lot_id
 * @property int $quantity_milli
 */
class StockLotLevel extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'article_id',
        'location_id',
        'lot_id',
        'quantity_milli',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity_milli' => 'integer'];
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

    /** @return BelongsTo<StockLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }
}
