<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A check of a VAT number in the EU's VIES register and its answer.
 * Append-only: the model refuses changes and a database trigger backs it up.
 *
 * @property int $id
 * @property int $company_id
 * @property ?int $customer_id
 * @property string $vat_number
 * @property bool $valid
 * @property ?string $name
 * @property ?string $address
 * @property ?string $request_identifier
 * @property CarbonImmutable $checked_at
 * @property int $checked_by
 */
class VatNumberCheck extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'customer_id',
        'vat_number',
        'valid',
        'name',
        'address',
        'request_identifier',
        'checked_at',
        'checked_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'valid' => 'boolean',
            'checked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('VAT number checks cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('VAT number checks cannot be deleted.');
        });
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
