<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Article;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one package holds: an article, its batch or serial number when it is
 * tracked, and the quantity.
 *
 * @property int $id
 * @property int $company_id
 * @property int $shipment_package_id
 * @property int $article_id
 * @property ?int $lot_id
 * @property int $quantity_milli
 */
class ShipmentPackageLine extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'shipment_package_id',
        'article_id',
        'lot_id',
        'quantity_milli',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity_milli' => 'integer'];
    }

    /** @return BelongsTo<ShipmentPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ShipmentPackage::class, 'shipment_package_id');
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
