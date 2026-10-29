<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An article: a product or service with its price excluding VAT, its VAT
 * rate and the revenue account it is booked on.
 *
 * @property int $company_id
 * @property string $number
 * @property string $name
 * @property string $unit
 * @property Money $price_ore
 * @property int $vat_rate
 * @property int $account_id
 * @property bool $is_active
 */
class Article extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'number',
        'name',
        'unit',
        'price_ore',
        'vat_rate',
        'account_id',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_ore' => MoneyCast::class,
            'vat_rate' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return HasMany<InvoiceRow, $this> */
    public function invoiceRows(): HasMany
    {
        return $this->hasMany(InvoiceRow::class);
    }
}
