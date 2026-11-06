<?php

declare(strict_types=1);

namespace App\Modules\Banking\Models;

use App\Models\User;
use App\Modules\Banking\Domain\ConnectionStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company's consent to read its accounts in one bank (ADR-034). The
 * provider's session id is encrypted at rest, hidden from serialisation and
 * cleared when the consent ends; it is never logged.
 *
 * @property int $id
 * @property int $company_id
 * @property string $provider
 * @property string $institution_name
 * @property string $institution_country
 * @property string $psu_type
 * @property ?string $session_id
 * @property ConnectionStatus $status
 * @property ?CarbonImmutable $valid_until
 * @property int $connected_by
 * @property CarbonImmutable $connected_at
 * @property ?int $revoked_by
 * @property ?CarbonImmutable $revoked_at
 * @property ?CarbonImmutable $last_fetched_at
 * @property ?string $last_error
 * @property ?CarbonImmutable $reminded_at
 * @property int $consecutive_failures
 * @property ?CarbonImmutable $next_attempt_at
 */
class BankConnection extends Model
{
    use BelongsToCompany;

    /** After this many failed fetches in a row Översikt and the bank page say so. */
    public const int FAILURES_TO_TELL = 3;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'provider',
        'institution_name',
        'institution_country',
        'psu_type',
        'session_id',
        'status',
        'valid_until',
        'connected_by',
        'connected_at',
        'revoked_by',
        'revoked_at',
        'last_fetched_at',
        'last_error',
        'reminded_at',
        'consecutive_failures',
        'next_attempt_at',
    ];

    /** @var list<string> */
    protected $hidden = ['session_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'session_id' => 'encrypted',
            'status' => ConnectionStatus::class,
            'valid_until' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_fetched_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime',
            'consecutive_failures' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === ConnectionStatus::Active && $this->session_id !== null;
    }

    /** Whether the provider has failed so many times in a row that a person should know. */
    public function keepsFailing(): bool
    {
        return $this->isActive() && $this->consecutive_failures >= self::FAILURES_TO_TELL;
    }

    /** Days left of the consent, or null when the bank gave no end. */
    public function daysLeft(CarbonImmutable $today): ?int
    {
        return $this->valid_until === null ? null : (int) $today->startOfDay()->diffInDays($this->valid_until->startOfDay(), false);
    }

    /** @return HasMany<BankFeedAccount, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(BankFeedAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
