<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company's connection to its own Stripe account with a restricted,
 * read-only key (ADR-080 §7.1). The key and the webhook secret are encrypted
 * at rest with the application key, hidden from serialisation, never logged
 * and never shown again; only the last four characters of the key are kept
 * in clear. Disconnecting or a 401 from Stripe erases both at once (§7.7).
 *
 * @property int $id
 * @property int $company_id
 * @property string $status
 * @property string $account_id
 * @property bool $livemode
 * @property ?string $secret_key
 * @property string $key_last4
 * @property string $webhook_token
 * @property ?string $webhook_secret
 * @property ?int $connected_by
 * @property CarbonImmutable $connected_at
 * @property ?CarbonImmutable $ended_at
 * @property ?int $ended_by
 * @property ?string $end_reason
 * @property ?CarbonImmutable $initial_import_done_at
 * @property ?array<string, string> $cursor
 * @property ?int $last_event_created
 * @property ?CarbonImmutable $last_synced_at
 * @property int $consecutive_failures
 * @property int $key_version
 */
class StripeConnection extends Model
{
    use BelongsToCompany;

    public const string ACTIVE = 'ACTIVE';

    /** Stripe answered 401: the key was expired or rolled in Stripe. */
    public const string REVOKED = 'REVOKED';

    /** A person disconnected it in NONOX. */
    public const string DISCONNECTED = 'DISCONNECTED';

    protected $table = 'stripe_connections';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'status',
        'account_id',
        'livemode',
        'secret_key',
        'key_last4',
        'webhook_token',
        'webhook_secret',
        'connected_by',
        'connected_at',
        'ended_at',
        'ended_by',
        'end_reason',
        'initial_import_done_at',
        'cursor',
        'last_event_created',
        'last_synced_at',
        'consecutive_failures',
        'key_version',
    ];

    /** @var list<string> */
    protected $hidden = ['secret_key', 'webhook_secret', 'webhook_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'secret_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'connected_by' => 'integer',
            'connected_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'ended_by' => 'integer',
            'initial_import_done_at' => 'immutable_datetime',
            'cursor' => 'array',
            'last_event_created' => 'integer',
            'last_synced_at' => 'immutable_datetime',
            'consecutive_failures' => 'integer',
            'key_version' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE && $this->secret_key !== null && $this->secret_key !== '';
    }

    public function hasWebhook(): bool
    {
        return $this->webhook_secret !== null && $this->webhook_secret !== '';
    }

    /** The key as the page shows it: never more than the last four characters. */
    public function maskedKey(): string
    {
        return ($this->livemode ? 'rk_live_' : 'rk_test_').'…'.$this->key_last4;
    }

    /** @return HasMany<StripeSyncRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(StripeSyncRun::class, 'connection_id');
    }
}
