<?php

declare(strict_types=1);

namespace App\Modules\Pos\Models;

use App\Models\User;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Pos\Domain\ConnectionStatus;
use App\Modules\Pos\Domain\Provider;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company's connection to one till account (ADR-042). Tokens, keys and
 * the webhook signing key live in `credentials`, encrypted at rest, hidden
 * from serialisation, never logged, and cleared on disconnect. The sales,
 * links and log stay.
 *
 * Settings: sync_direction ONE_WAY (articles from NONOX to the till) or
 * TWO_WAY (also link the till's own products to articles), push_stock
 * (send stock changes to the till), product_groups (Onslip: VAT rate in
 * basis points => product group id).
 *
 * @property int $id
 * @property int $company_id
 * @property Provider $provider
 * @property ConnectionStatus $status
 * @property ?string $external_org_id
 * @property ?string $display_name
 * @property ?array<string, mixed> $credentials
 * @property string $webhook_token
 * @property array<string, mixed> $settings
 * @property ?int $location_id
 * @property ?CarbonImmutable $last_synced_at
 * @property ?string $last_error
 * @property int $connected_by
 * @property CarbonImmutable $connected_at
 * @property ?int $disconnected_by
 * @property ?CarbonImmutable $disconnected_at
 */
class PosConnection extends Model
{
    use BelongsToCompany;

    public const string ONE_WAY = 'ONE_WAY';

    public const string TWO_WAY = 'TWO_WAY';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'provider',
        'status',
        'external_org_id',
        'display_name',
        'credentials',
        'webhook_token',
        'settings',
        'location_id',
        'last_synced_at',
        'last_error',
        'connected_by',
        'connected_at',
        'disconnected_by',
        'disconnected_at',
    ];

    /** @var list<string> */
    protected $hidden = ['credentials', 'webhook_token'];

    /** @var array<string, mixed> */
    protected $attributes = ['settings' => '{}'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'status' => ConnectionStatus::class,
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'last_synced_at' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime',
            'disconnected_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === ConnectionStatus::Active && $this->credentials !== null;
    }

    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    public function syncDirection(): string
    {
        return ($this->settings['sync_direction'] ?? self::ONE_WAY) === self::TWO_WAY ? self::TWO_WAY : self::ONE_WAY;
    }

    /** Whether articles are sent to the till at all. */
    public function pushesProducts(): bool
    {
        return (bool) ($this->settings['push_products'] ?? true);
    }

    public function pushesStock(): bool
    {
        return $this->provider->supportsStock() && (bool) ($this->settings['push_stock'] ?? true);
    }

    /** Onslip's product group for a VAT rate in basis points, or null when not mapped. */
    public function productGroup(int $vatRate): ?int
    {
        $groups = (array) ($this->settings['product_groups'] ?? []);
        $group = $groups[(string) $vatRate] ?? null;

        return is_numeric($group) ? (int) $group : null;
    }

    public function label(): string
    {
        return $this->display_name !== null && $this->display_name !== ''
            ? "{$this->provider->label()} ({$this->display_name})"
            : $this->provider->label();
    }

    /** @param  array<string, mixed>  $context */
    public function log(string $level, string $message, array $context = []): void
    {
        PosLogEntry::create([
            'company_id' => $this->company_id,
            'pos_connection_id' => $this->id,
            'level' => $level,
            'message' => mb_substr($message, 0, 500),
            'context' => $context,
            'created_at' => now(),
        ]);
    }

    /** @return HasMany<PosProductLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(PosProductLink::class);
    }

    /** @return HasMany<PosLogEntry, $this> */
    public function logEntries(): HasMany
    {
        return $this->hasMany(PosLogEntry::class);
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
