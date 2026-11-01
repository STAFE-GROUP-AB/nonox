<?php

declare(strict_types=1);

namespace App\Modules\Connect\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event on its way to one webhook (ADR-032), and the log of how it went:
 * attempts, the receiver's last status code or a short reason, never the
 * receiver's answer. The body is the event we sent: ids, references and
 * amounts, never personal data.
 *
 * @property string $id
 * @property int $company_id
 * @property string $webhook_id
 * @property string $event_id
 * @property string $event_type
 * @property string $body
 * @property string $status
 * @property int $attempts
 * @property ?CarbonImmutable $next_attempt_at
 * @property ?CarbonImmutable $last_attempt_at
 * @property ?int $last_status_code
 * @property ?string $last_error
 * @property ?int $last_duration_ms
 * @property ?CarbonImmutable $delivered_at
 * @property CarbonImmutable $created_at
 */
class ConnectWebhookDelivery extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const string PENDING = 'pending';

    public const string DELIVERED = 'delivered';

    /** Every attempt failed. */
    public const string FAILED = 'failed';

    /** Not sent: the webhook was turned off or removed before it could be. */
    public const string CANCELLED = 'cancelled';

    protected $table = 'connect_webhook_deliveries';

    /** @var list<string> */
    protected $fillable = ['company_id', 'webhook_id', 'event_id', 'event_type', 'body', 'status', 'next_attempt_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_status_code' => 'integer',
            'last_duration_ms' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ConnectWebhook, $this> */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(ConnectWebhook::class, 'webhook_id');
    }
}
