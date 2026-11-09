<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Models;

use App\Models\Article;
use App\Modules\Inventory\Models\StockLocation;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Väntar på lager" (ADR-073 §5): an ingredient a day's sales should have
 * drawn from stock, held back because the stock would go below zero while
 * negative stock is off. Tried again each night; a person can also leave
 * it to the next stock count (DISMISSED).
 *
 * @property int $id
 * @property int $company_id
 * @property CarbonImmutable $business_date
 * @property int $location_id
 * @property int $article_id
 * @property int $quantity_milli
 * @property int $kitchen_consumption_run_id
 * @property string $status
 * @property ?int $resolved_by
 */
class ConsumptionWait extends Model
{
    use BelongsToCompany;

    public const string WAITING = 'WAITING';

    public const string POSTED = 'POSTED';

    public const string DISMISSED = 'DISMISSED';

    protected $table = 'kitchen_consumption_waits';

    /** @var list<string> */
    protected $fillable = ['company_id', 'business_date', 'location_id', 'article_id', 'quantity_milli', 'kitchen_consumption_run_id', 'status', 'resolved_by', 'resolved_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'quantity_milli' => 'integer',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }
}
