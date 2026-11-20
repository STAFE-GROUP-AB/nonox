<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Domain\Pricing\PriceSource;
use App\Models\Article;
use App\Models\Concerns\HasRegisterUnit;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of a quote template (ADR-046). With an article, the quote made from
 * it takes the article's price, unit and VAT rate of that day; without one
 * it is a free row with its own.
 *
 * @property int $id
 * @property int $company_id
 * @property int $template_id
 * @property ?int $article_id
 * @property ?string $description
 * @property string $quantity
 * @property ?string $unit
 * @property int $unit_price_ore
 * @property int $vat_rate basis points
 * @property int $discount_bp
 * @property int $position
 * @property ?PriceSource $price_source where the price came from (ADR-082 §5)
 * @property ?array<string, mixed> $price_explanation ids, enums, tiers and minor amounts only
 */
class QuoteTemplateRow extends Model
{
    use BelongsToCompany;
    use HasRegisterUnit;

    protected $table = 'crm_quote_template_rows';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'template_id',
        'article_id',
        'description',
        'quantity',
        'unit',
        'unit_id',
        'unit_price_ore',
        'vat_rate',
        'discount_bp',
        'position',
        'price_source',
        'price_explanation',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_price_ore' => 'integer',
            'vat_rate' => 'integer',
            'discount_bp' => 'integer',
            'position' => 'integer',
            'price_source' => PriceSource::class,
            'price_explanation' => 'array',
        ];
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<QuoteTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(QuoteTemplate::class, 'template_id');
    }
}
