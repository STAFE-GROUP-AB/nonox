<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\FiscalYear;
use App\Models\Voucher;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The stock valuation saved at a year end (underlag för varulagret vid
 * bokslut): every line as it was, the method, and the change booked against
 * the stock account. Append-only, like the closing entries.
 *
 * @property int $id
 * @property int $company_id
 * @property ?int $fiscal_year_id
 * @property CarbonImmutable $valuation_date
 * @property string $method
 * @property int $cost_value_ore
 * @property int $value_ore
 * @property int $book_value_ore
 * @property int $booked_change_ore
 * @property int $stock_account
 * @property int $change_account
 * @property list<array<string, mixed>> $lines
 * @property ?string $voucher_id
 * @property ?int $created_by
 * @property CarbonImmutable $created_at
 */
class StockValuation extends Model
{
    use BelongsToCompany;

    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'valuation_date',
        'method',
        'cost_value_ore',
        'value_ore',
        'book_value_ore',
        'booked_change_ore',
        'stock_account',
        'change_account',
        'lines',
        'voucher_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'valuation_date' => 'immutable_date',
            'cost_value_ore' => 'integer',
            'value_ore' => 'integer',
            'book_value_ore' => 'integer',
            'booked_change_ore' => 'integer',
            'stock_account' => 'integer',
            'change_account' => 'integer',
            'lines' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Stock valuations cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('Stock valuations cannot be deleted.');
        });
    }

    /** @return BelongsTo<FiscalYear, $this> */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
