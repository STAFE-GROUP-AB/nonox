<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A row that a SIE file marks as added (#RTRANS) or removed (#BTRANS) after
 * the voucher was first registered in the program it came from. Kept for
 * traceability (AGENTS.md §9.2) and written back on export. Never changed; a
 * database trigger backs this up.
 *
 * @property string $voucher_id
 * @property ?int $voucher_row_id
 * @property string $change
 * @property int $position
 * @property int $account_number
 * @property Money $amount_ore
 * @property ?CarbonImmutable $transaction_date
 * @property ?string $description
 * @property ?string $quantity
 * @property ?string $signature
 * @property ?int $cost_center_id
 * @property ?int $project_id
 */
class VoucherRowHistory extends Model
{
    use BelongsToCompany;

    public const string ADDED = 'ADDED';

    public const string REMOVED = 'REMOVED';

    protected $table = 'voucher_row_history';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'voucher_id',
        'voucher_row_id',
        'change',
        'position',
        'account_number',
        'amount_ore',
        'transaction_date',
        'description',
        'quantity',
        'signature',
        'cost_center_id',
        'project_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'account_number' => 'integer',
            'amount_ore' => MoneyCast::class,
            'transaction_date' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        $refuse = function (): never {
            throw new LogicException('Voucher row history cannot be changed or removed.');
        };

        static::updating($refuse);
        static::deleting($refuse);
    }
}
