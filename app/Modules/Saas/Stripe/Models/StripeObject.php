<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A Stripe object as it was fetched (ADR-080 §7.2): a new row only when the
 * payload's SHA-256 changes, never changed afterwards (model and trigger), so
 * what NONOX computed from is kept as received. Pruned after a disconnect
 * (§8). Customers are not stored here; see StripeCustomerLink.
 *
 * @property int $id
 * @property int $company_id
 * @property int $connection_id
 * @property string $type
 * @property string $stripe_id
 * @property array<string, mixed> $payload
 * @property string $sha256
 * @property CarbonImmutable $fetched_at
 */
class StripeObject extends Model
{
    use BelongsToCompany;

    public const string PRODUCT = 'product';

    public const string PRICE = 'price';

    public const string COUPON = 'coupon';

    public const string SUBSCRIPTION = 'subscription';

    public $timestamps = false;

    protected $table = 'stripe_objects';

    /** @var list<string> */
    protected $fillable = ['company_id', 'connection_id', 'type', 'stripe_id', 'payload', 'sha256', 'fetched_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'payload' => 'array',
            'fetched_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A fetched Stripe object is kept as received; store a new row instead.');
        });
    }
}
