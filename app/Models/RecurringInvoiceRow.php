<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pricing\PriceSource;
use App\Models\Concerns\HasRegisterUnit;
use App\Models\Concerns\SalesRowFields;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of an invoicing agreement, shaped like an invoice row.
 *
 * @property string $recurring_invoice_id
 * @property ?int $article_id
 * @property string $description
 * @property string $quantity
 * @property string $unit
 * @property Money $unit_price_ore
 * @property int $discount_bp
 * @property int $vat_rate
 * @property int $account_id
 * @property Money $net_ore
 * @property int $position
 * @property ?PriceSource $price_source where the price came from (ADR-082 §5)
 * @property ?array<string, mixed> $price_explanation ids, enums, tiers and minor amounts only
 */
class RecurringInvoiceRow extends Model
{
    use BelongsToCompany;
    use HasRegisterUnit;
    use SalesRowFields;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'recurring_invoice_id',
        'article_id',
        'description',
        'quantity',
        'unit',
        'unit_id',
        'unit_price_ore',
        'discount_bp',
        'vat_rate',
        'account_id',
        'net_ore',
        'position',
        'price_source',
        'price_explanation',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_price_ore' => MoneyCast::class,
            'net_ore' => MoneyCast::class,
            'discount_bp' => 'integer',
            'vat_rate' => 'integer',
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

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
