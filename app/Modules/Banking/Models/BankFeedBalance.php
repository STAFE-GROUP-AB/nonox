<?php

declare(strict_types=1);

namespace App\Modules\Banking\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A balance the bank reported for a day. A later fetch the same day
 * replaces it: it is a reading, not räkenskapsinformation.
 *
 * @property int $id
 * @property int $company_id
 * @property int $bank_feed_account_id
 * @property CarbonImmutable $balance_date
 * @property string $balance_type
 * @property Money $amount_ore
 * @property string $currency
 * @property CarbonImmutable $fetched_at
 */
class BankFeedBalance extends Model
{
    use BelongsToCompany;

    /** Balance types in the order a statement balance is chosen (ISO 20022). */
    public const array PREFERRED = ['CLBD', 'ITBD', 'XPCD', 'CLAV', 'ITAV', 'OPBD'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'bank_feed_account_id',
        'balance_date',
        'balance_type',
        'amount_ore',
        'currency',
        'fetched_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'balance_date' => 'immutable_date',
            'amount_ore' => MoneyCast::class,
            'fetched_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<BankFeedAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BankFeedAccount::class, 'bank_feed_account_id');
    }
}
