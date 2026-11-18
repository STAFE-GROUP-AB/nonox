<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Article;
use App\Models\Supplier;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A batch or a serial number of a tracked article (ADR-081 E12). It carries
 * no cost: valuation stays per article. Written only by StockLedger when
 * goods come in.
 *
 * @property int $id
 * @property int $company_id
 * @property int $article_id
 * @property string $kind
 * @property string $code
 * @property ?CarbonImmutable $expires_on
 * @property CarbonImmutable $received_on
 * @property ?int $supplier_id
 * @property ?int $stock_receipt_line_id
 */
class StockLot extends Model
{
    use BelongsToCompany;

    public const string BATCH = 'BATCH';

    public const string SERIAL = 'SERIAL';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'article_id',
        'kind',
        'code',
        'expires_on',
        'received_on',
        'supplier_id',
        'stock_receipt_line_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_on' => 'immutable_date',
            'received_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<StockLotLevel, $this> */
    public function levels(): HasMany
    {
        return $this->hasMany(StockLotLevel::class, 'lot_id');
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'lot_id');
    }
}
