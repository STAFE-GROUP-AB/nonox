<?php

declare(strict_types=1);

namespace App\Modules\Pos\Models;

use App\Models\Article;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a till sale. MATCHED lines moved stock (stock_movement_id),
 * UNMATCHED lines wait for a person to say which article they are, and
 * SKIPPED lines had no goods.
 *
 * @property int $id
 * @property int $company_id
 * @property int $pos_sale_id
 * @property int $line_no
 * @property ?string $external_product_id
 * @property ?string $external_variant_id
 * @property ?string $barcode
 * @property ?string $sku
 * @property string $name
 * @property int $quantity_milli
 * @property int $amount_ore
 * @property ?int $vat_rate
 * @property string $status
 * @property ?int $article_id
 * @property ?int $stock_movement_id
 */
class PosSaleRow extends Model
{
    use BelongsToCompany;

    public const string MATCHED = 'MATCHED';

    public const string UNMATCHED = 'UNMATCHED';

    public const string SKIPPED = 'SKIPPED';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'pos_sale_id',
        'line_no',
        'external_product_id',
        'external_variant_id',
        'barcode',
        'sku',
        'name',
        'quantity_milli',
        'amount_ore',
        'vat_rate',
        'status',
        'article_id',
        'stock_movement_id',
    ];

    /** @return BelongsTo<PosSale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
