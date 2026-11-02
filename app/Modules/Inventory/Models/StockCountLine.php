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
 * @property int $stock_count_id
 * @property int $article_id
 * @property int $location_id
 * @property int $system_milli
 * @property ?int $counted_milli
 * @property ?string $reason
 */
class StockCountLine extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'stock_count_id',
        'article_id',
        'location_id',
        'system_milli',
        'counted_milli',
        'reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'system_milli' => 'integer',
            'counted_milli' => 'integer',
        ];
    }

    public function differenceMilli(): ?int
    {
        return $this->counted_milli === null ? null : $this->counted_milli - $this->system_milli;
    }

    /** @return BelongsTo<StockCount, $this> */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
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
