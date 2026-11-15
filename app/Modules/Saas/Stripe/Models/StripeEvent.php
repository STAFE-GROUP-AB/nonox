<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A Stripe event NONOX has seen, from the webhook or the Events list
 * (ADR-080 §7.3–§7.4), unique per connection so it is handled once. Only the
 * event's id, type, the id of the object it is about and its time are kept:
 * the payload is a hint, the object is fetched again with the company's key.
 *
 * @property int $id
 * @property int $company_id
 * @property int $connection_id
 * @property string $event_id
 * @property string $type
 * @property ?string $object_id
 * @property int $event_created
 * @property string $received_from
 * @property CarbonImmutable $received_at
 * @property ?CarbonImmutable $processed_at
 */
class StripeEvent extends Model
{
    use BelongsToCompany;

    public const string FROM_WEBHOOK = 'WEBHOOK';

    public const string FROM_LIST = 'LIST';

    protected $table = 'stripe_events';

    /** @var list<string> */
    protected $fillable = ['company_id', 'connection_id', 'event_id', 'type', 'object_id', 'event_created', 'received_from', 'received_at', 'processed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'event_created' => 'integer',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
