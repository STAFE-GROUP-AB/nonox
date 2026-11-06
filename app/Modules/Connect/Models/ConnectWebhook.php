<?php

declare(strict_types=1);

namespace App\Modules\Connect\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A webhook subscription (ADR-032): one company's events sent to one HTTPS
 * address, signed with a secret of its own. The secret is encrypted with the
 * application key (it is needed in plain text to sign), never shown after it
 * was created.
 *
 * @property string $id
 * @property int $company_id
 * @property string $url
 * @property ?string $description
 * @property list<string> $events
 * @property string $secret
 * @property string $secret_prefix
 * @property ?CarbonImmutable $disabled_at
 * @property ?string $disabled_reason
 * @property ?CarbonImmutable $last_success_at
 * @property ?CarbonImmutable $last_failure_at
 * @property int $created_by
 * @property CarbonImmutable $created_at
 */
class ConnectWebhook extends Model
{
    use BelongsToCompany;
    use HasUuids;

    /** Turned off by a person under Anslutningar. */
    public const string DISABLED_BY_USER = 'disabled_by_user';

    /** Turned off by NONOX after a delivery failed every attempt. */
    public const string DISABLED_FAILING = 'failing';

    /** Turned off because NONOX API was turned off for the company. */
    public const string DISABLED_MODULE = 'module_disabled';

    protected $table = 'connect_webhooks';

    /** @var list<string> */
    protected $fillable = ['company_id', 'url', 'description', 'events', 'secret', 'secret_prefix', 'created_by'];

    /** @var list<string> */
    protected $hidden = ['secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'secret' => 'encrypted',
            'disabled_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->disabled_at === null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('disabled_at');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ConnectWebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ConnectWebhookDelivery::class, 'webhook_id');
    }
}
