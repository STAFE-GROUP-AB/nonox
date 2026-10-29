<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A VAT period that has been booked to the VAT account (2650). Never
 * changed; a database trigger backs this up.
 *
 * @property int $company_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property array<int, int> $boxes
 * @property string $voucher_id
 * @property int $created_by
 */
class VatReport extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'period_start',
        'period_end',
        'boxes',
        'voucher_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'boxes' => 'array',
        ];
    }

    protected static function booted(): void
    {
        $refuse = function (): never {
            throw new LogicException('A reported VAT period cannot be changed or removed.');
        };

        static::updating($refuse);
        static::deleting($refuse);
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
