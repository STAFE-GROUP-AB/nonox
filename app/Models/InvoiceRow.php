<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasRegisterUnit;
use App\Models\Concerns\SalesRowFields;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of an invoice: quantity × price, less any discount, at a VAT rate,
 * booked on a revenue account.
 *
 * @property bool $is_labour
 * @property string $invoice_id
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
 * @property ?int $foreign_unit_price_minor the price in the invoice's currency, when it is not kronor
 * @property ?int $foreign_net_minor
 * @property ?string $vat_exemption why a row sold in Sweden carries 0 % VAT (VatExemptionReason, #54)
 * @property ?string $vat_exemption_text the exemption printed on the invoice
 */
class InvoiceRow extends Model
{
    use BelongsToCompany;
    use HasRegisterUnit;
    use SalesRowFields;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'invoice_id',
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
        'is_labour',
        'foreign_unit_price_minor',
        'foreign_net_minor',
        'vat_exemption',
        'vat_exemption_text',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_labour' => 'boolean',
            'unit_price_ore' => MoneyCast::class,
            'net_ore' => MoneyCast::class,
            'discount_bp' => 'integer',
            'vat_rate' => 'integer',
            'position' => 'integer',
            'foreign_unit_price_minor' => 'integer',
            'foreign_net_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
