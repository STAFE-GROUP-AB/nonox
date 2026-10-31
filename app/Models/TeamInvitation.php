<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Jetstream\TeamInvitation as JetstreamTeamInvitation;

/**
 * @property ?string $name the invited person's name, when the inviter knows it
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
    ];

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
