<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Crm\Calendar\CalendarProviderKey;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's own Google or Microsoft calendar, connected in one company
 * (ADR-049). The OAuth tokens and the account's address are encrypted at
 * rest with the application key and never shown or logged.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property CalendarProviderKey $provider
 * @property ?string $account_email
 * @property bool $organisation_account a Google Workspace or Microsoft work or school account, not a private one
 * @property string $access_token
 * @property ?string $refresh_token
 * @property ?CarbonImmutable $token_expires_at
 * @property ?string $calendar_id
 * @property ?string $calendar_name
 * @property ?string $sync_cursor
 * @property bool $include_details
 * @property string $status
 * @property ?string $last_error
 * @property ?CarbonImmutable $last_synced_at
 * @property ?CarbonImmutable $cursor_started_at when the calendar was last read from the start
 * @property CarbonImmutable $created_at
 */
class CalendarConnection extends Model
{
    use BelongsToCompany;

    public const string ACTIVE = 'ACTIVE';

    public const string ERROR = 'ERROR';

    public const string REVOKED = 'REVOKED';

    protected $table = 'crm_calendar_connections';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'user_id',
        'provider',
        'account_email',
        'organisation_account',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'calendar_id',
        'calendar_name',
        'sync_cursor',
        'include_details',
        'status',
        'last_error',
        'last_synced_at',
        'cursor_started_at',
    ];

    /** @var list<string> */
    protected $hidden = ['access_token', 'refresh_token', 'sync_cursor'];

    /** @var array<string, mixed> */
    protected $attributes = ['include_details' => false, 'status' => self::ACTIVE, 'organisation_account' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'provider' => CalendarProviderKey::class,
            'account_email' => 'encrypted',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'include_details' => 'boolean',
            'organisation_account' => 'boolean',
            'token_expires_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'cursor_started_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CalendarEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CalendarEvent::class, 'connection_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE && $this->calendar_id !== null;
    }
}
