<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\TeamInvitation as JetstreamTeamInvitation;

/**
 * @property ?string $name the invited person's name, when the inviter knows it
 * @property ?int $invited_by the user who sent the invitation
 */
class TeamInvitation extends JetstreamTeamInvitation
{
    /** An invitation that has not been accepted within this many days can no longer be used. */
    public const VALID_DAYS = 14;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'role',
        'name',
        'invited_by',
    ];

    /**
     * The sender defaults to whoever is signed in, so that every invitation,
     * whichever action creates it, can be withdrawn with its sender.
     */
    protected static function booted(): void
    {
        static::creating(function (self $invitation): void {
            $userId = Auth::id();

            if ($invitation->invited_by === null && is_int($userId)) {
                $invitation->invited_by = $userId;
            }
        });
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => User::normaliseEmail($value));
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Get the team that the invitation belongs to.
     */
    /** @return BelongsTo<Company, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'team_id');
    }

    public function expiresAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->created_at ?? now())->addDays(self::VALID_DAYS);
    }

    public function hasExpired(): bool
    {
        return $this->expiresAt()->isPast();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStillValid(Builder $query): Builder
    {
        return $query->where('created_at', '>', now()->subDays(self::VALID_DAYS));
    }
}
