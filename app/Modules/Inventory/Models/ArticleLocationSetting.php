<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Article;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The reorder point of an article in one warehouse (ADR-081 E13). It
 * replaces the article's company-wide reorder point in the purchase
 * proposal: with a setting here, the warehouses are judged one by one.
 *
 * @property int $id
 * @property int $company_id
 * @property int $article_id
 * @property int $warehouse_id
 * @property int $reorder_point_milli
 * @property ?int $reorder_quantity_milli
 */
class ArticleLocationSetting extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'article_id',
        'warehouse_id',
        'reorder_point_milli',
        'reorder_quantity_milli',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reorder_point_milli' => 'integer',
            'reorder_quantity_milli' => 'integer',
        ];
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'warehouse_id');
    }
}
