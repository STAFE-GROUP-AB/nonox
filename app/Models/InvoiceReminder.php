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
 * A reminder sent for an overdue invoice. Never changed.
 *
 * @property string $invoice_id
 * @property int $sequence
 * @property CarbonImmutable $sent_on
 * @property CarbonImmutable $pay_by
 * @property Money $remaining_ore
 * @property string $sent_to
 * @property int $created_by
 */
class InvoiceReminder extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'invoice_id',
        'sequence',
        'sent_on',
        'pay_by',
        'remaining_ore',
        'sent_to',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'sent_on' => 'immutable_date',
            'pay_by' => 'immutable_date',
            'remaining_ore' => MoneyCast::class,
        ];
    }

    protected static function booted(): void
    {
        $refuse = function (): never {
            throw new LogicException('A sent reminder cannot be changed or removed.');
        };

        static::updating($refuse);
        static::deleting($refuse);
    }
}
