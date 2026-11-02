<?php

declare(strict_types=1);

namespace App\Modules\Pos\Models;

use App\Models\Article;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An article and the till's product for it, per connection (ADR-042).
 *
 * @property int $id
 * @property int $company_id
 * @property int $pos_connection_id
 * @property int $article_id
 * @property string $external_product_id
 * @property ?string $external_variant_id
 * @property ?string $pushed_hash
 * @property bool $stock_tracking
 * @property ?CarbonImmutable $synced_at
 * @property ?string $last_error
 */
class PosProductLink extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'pos_connection_id',
        'article_id',
        'external_product_id',
        'external_variant_id',
        'pushed_hash',
        'stock_tracking',
        'synced_at',
        'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stock_tracking' => 'boolean',
            'synced_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<PosConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(PosConnection::class, 'pos_connection_id');
    }
}
