<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Article;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The quantity of an article at a location: the sum of its movements, kept
 * by StockLedger in the same transaction as each movement and checked by
 * `php artisan inventory:verify`.
 *
 * @property int $id
 * @property int $company_id
 * @property int $article_id
 * @property int $location_id
 * @property int $quantity_milli
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class StockLevel extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'article_id',
        'location_id',
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
}
