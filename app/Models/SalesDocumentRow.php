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
use Illuminate\Support\Str;

/**
 * A row of a quote or an order, shaped like an invoice row. Rows are
 * written anew on every save; line_key stays the same, so what hangs on a
 * row (a bill of materials copy, ADR-071 §4) follows it.
 *
 * @property string $sales_document_id
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
 * @property ?string $line_key
 */
class SalesDocumentRow extends Model
{
    use BelongsToCompany;
    use HasRegisterUnit;
    use SalesRowFields {
        asInput as private rowFields;
    }

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'sales_document_id',
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
        'line_key',
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
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            $row->line_key ??= (string) Str::uuid();
        });
    }

    /**
     * As SalesRowFields, with the row's key, so a quote's rows keep their
     * keys in the order made from it.
     *
     * @return array<string, mixed>
     */
    public function asInput(): array
    {
        return [...$this->rowFields(), 'line_key' => $this->line_key];
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
