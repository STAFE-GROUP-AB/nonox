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
 * @property int $pick_list_id
 * @property int $article_id
 * @property ?int $location_id
 * @property int $quantity_milli
 * @property ?int $picked_milli
 * @property int $position
 */
class PickListLine extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'pick_list_id',
        'article_id',
        'location_id',
        'quantity_milli',
        'picked_milli',
        'position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'picked_milli' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<PickList, $this> */
    public function pickList(): BelongsTo
    {
        return $this->belongsTo(PickList::class);
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
