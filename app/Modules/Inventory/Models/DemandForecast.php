<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Article;
use App\Models\User;
use App\Modules\Inventory\Domain\ForecastPeriodKind;
use App\Modules\Inventory\Domain\ForecastSource;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The expected demand for one article in one period (ADR-081 gap 5): a week
 * from Monday or a calendar month, in milli-units of the article's stock
 * unit. Typed by a person (MANUAL) or computed from the stock history
 * (HISTORY); always editable. One row per article, period kind and start.
 * Not bookkeeping.
 *
 * @property int $id
 * @property int $company_id
 * @property int $article_id
 * @property CarbonImmutable $period_start
 * @property ForecastPeriodKind $period_kind
 * @property int $quantity_milli
 * @property ForecastSource $source
 * @property ?int $created_by
 */
class DemandForecast extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'article_id',
        'period_start',
        'period_kind',
        'quantity_milli',
        'source',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_kind' => ForecastPeriodKind::class,
            'quantity_milli' => 'integer',
            'source' => ForecastSource::class,
        ];
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
