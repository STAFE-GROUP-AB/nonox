<?php

declare(strict_types=1);

namespace App\Modules\Saas\Models;

use App\Models\Customer;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A change that became known after a month was closed and that is effective
 * in that month or earlier (ADR-080 §3.4, SA-R22). It is booked in the first
 * open month as Rättelse; the closed month is never touched. Append-only.
 *
 * @property int $id
 * @property int $company_id
 * @property CarbonImmutable $booked_month
 * @property CarbonImmutable $affects_month
 * @property int $customer_id
 * @property int $amount_ore
 * @property ?int $version_id
 * @property ?CarbonImmutable $created_at
 */
class SaasCorrection extends Model
{
    use BelongsToCompany;

    public const ?string UPDATED_AT = null;

    protected $table = 'saas_corrections';

    /** @var list<string> */
    protected $fillable = ['company_id', 'booked_month', 'affects_month', 'customer_id', 'amount_ore', 'version_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'booked_month' => 'immutable_date',
            'affects_month' => 'immutable_date',
            'customer_id' => 'integer',
            'amount_ore' => 'integer',
            'version_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Corrections cannot be changed; a new correction is written instead.');
        });

        static::deleting(function (): never {
            throw new LogicException('Corrections cannot be deleted.');
        });
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
